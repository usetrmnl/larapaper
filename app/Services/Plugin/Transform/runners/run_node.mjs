/*
 * Trusted runner for the local JavaScript transform provider.
 *
 * Started once per transform by LocalTransformProvider as
 *   node --permission --disallow-code-generation-from-strings --no-addons --max-old-space-size=N \
 *        run_node.mjs <timeoutMs> <allowNetwork> <bufferLimitBytes>
 * with an empty environment. The permission model grants nothing but reading this file,
 * so the process itself cannot touch the filesystem, spawn processes, or start workers.
 *
 * User code never runs in this realm: it is evaluated in a fresh node:vm context whose
 * global object has a null prototype and which only ever receives and returns strings.
 * The context has no `process`, `require`, `import()` or sockets, and code generation from
 * strings and WebAssembly are disabled for it. The web APIs it does get (fetch, timers,
 * AbortController, TextEncoder, URL, URLSearchParams, ...) are implemented inside the context by `sandboxBootstrap`:
 * `fetch()` only queues a request description that PHP performs, and timers are scheduled
 * by this runner within the transform's time budget.
 *
 * Line-based JSON protocol:
 *   stdin  1st line: {"code": "<user script>", "input": <payload>}
 *   stdout          {"type": "fetch", "requests": [{"id", "url", "method", "headers": [[name, value]], "body"}]}
 *   stdin           {"type": "responses", "responses": [{"id", "status", "statusText", "url", "headers", "body"} | {"id", "error"}]}
 *   stdout last:    {"type": "result", "ok": true, "output": <result>, "logs": [...]} or {"type": "result", "ok": false, "error": "...", "logs": [...]}
 */

import vm from 'node:vm';
import readline from 'node:readline';
import { setTimeout as sleep } from 'node:timers/promises';
import { types } from 'node:util';

const timeout = Math.max(1, Number.parseInt(process.argv[2] ?? '', 10) || 5000);
const allowNetwork = process.argv[3] === '1';
const bufferLimitBytes = Math.max(0, Number.parseInt(process.argv[4] ?? '', 10) || 64 * 1024 * 1024);
const deadline = Date.now() + timeout;

// Defence in depth: nothing below hands `process` to user code, but drop what it could abuse anyway.
for (const name of ['kill', 'binding', '_linkedBinding', 'dlopen']) {
    process[name] = () => {
        throw new Error('restricted');
    };
}

// A rejection the sandbox leaves unhandled must not crash the runner or be inspected by it.
process.on('unhandledRejection', () => {});

const lines = readline.createInterface({ input: process.stdin, crlfDelay: Infinity })[Symbol.asyncIterator]();

const nextLine = async () => {
    const { value, done } = await lines.next();

    return done ? undefined : value;
};

/**
 * JSON.stringify writes a lone UTF-16 surrogate (e.g. from slicing text through an emoji) as a
 * `\udXXX` escape, which is not valid UTF-8 for PHP. Well-formed pairs are written literally, so
 * every surrogate escape not preceded by an escaping backslash is lone: replace it with U+FFFD.
 */
const LONE_SURROGATE_ESCAPE = /(?<!\\)((?:\\\\)*)\\u[dD][89a-fA-F][0-9a-fA-F]{2}/g;
const encode = (message) => `${JSON.stringify(message).replace(LONE_SURROGATE_ESCAPE, '$1\\ufffd')}\n`;

const send = (message) => {
    process.stdout.write(encode(message));
};

/**
 * Write the final result and exit once it is flushed (stdout is asynchronous on some pipes).
 */
const finish = (result) => {
    process.stdout.write(encode(result), () => process.exit(0));
};

/**
 * Dynamic `import()` rejects with an error object from this realm, which would hand the sandbox
 * a host object. `import` is a keyword: it cannot be escaped or generated, so a source check is sound.
 */
const DYNAMIC_IMPORT = /\bimport\s*(?:\(|\/[/*])/;

/**
 * Turn the user's code into a classic script. ES-module style (`export function run`, `export default`)
 * is accepted. The code runs inside a `try` block so nothing it throws ever reaches this realm, and
 * `run` (or the deprecated TRMNL name `transform`) is registered from inside that block so
 * `const run = ...` works too. `try {` shares the first line, so reported line numbers match the user's code.
 */
const toScript = (code) => (/^\s*(['"])use strict\1/.test(code) ? "'use strict'; try {" : 'try {') + code
    .replace(/^([ \t]*)export\s+default\s+/gm, '$1module.exports = ')
    .replace(/^[ \t]*export\s*\{[^}]*\}\s*;?/gm, '')
    .replace(/^([ \t]*)export\s+(?=(?:async\s+)?function\b|const\b|let\b|var\b|class\b)/gm, '$1')
    + "\n;__trmnlRegister(typeof run === 'function' ? run : undefined, typeof transform === 'function' ? transform : undefined);\n} catch (__trmnlError) { __trmnlFail(__trmnlError); }\n";

/**
 * Prepares the sandbox. This function is never called in this realm: its source text is
 * evaluated inside the context, so everything it creates belongs to the sandbox realm, and
 * it may not refer to anything outside its own body. It captures the intrinsics it relies
 * on before user code runs, so a script that tampers with built-ins only breaks itself.
 */
function sandboxBootstrap(options) {
    'use strict';

    const { apply, construct, defineProperty, getOwnPropertyDescriptor, getPrototypeOf } = Reflect;
    const ArrayCtor = Array;
    const ArrayFrom = Array.from;
    const MapCtor = Map;
    const ProxyCtor = Proxy;
    const PromiseCtor = Promise;
    const NumberCtor = Number;
    const StringCtor = String;
    const StringFromCharCode = String.fromCharCode;
    const MathTrunc = Math.trunc;
    const MathMax = Math.max;
    const DateNow = Date.now;
    const JSONStringify = JSON.stringify;
    const JSONParse = JSON.parse;
    const freeze = Object.freeze;
    const globals = globalThis;

    const define = (name, value) => defineProperty(globals, name, { value, writable: false, configurable: false, enumerable: false });
    const expose = (name, value) => defineProperty(globals, name, { value, writable: true, configurable: true, enumerable: false });

    /* --- console, errors, result state --- */

    const MAX_LOG_LENGTH = 65536;
    const logs = [];
    let logLength = 0;
    const describe = (value) => {
        if (typeof value === 'string') return value;
        try {
            const json = JSONStringify(value);
            return json === undefined ? StringCtor(value) : json;
        } catch {
            try { return StringCtor(value); } catch { return '[unprintable]'; }
        }
    };
    const log = (...args) => {
        if (logLength >= MAX_LOG_LENGTH) return;
        const line = args.map(describe).join(' ');
        logLength += line.length;
        logs.push(line);
    };
    const formatError = (error) => {
        try {
            if (error instanceof Error) {
                const location = /transform\.js:(\d+)/.exec(StringCtor(error.stack ?? ''));
                return StringCtor(error.name) + ': ' + StringCtor(error.message) + (location ? ' (line ' + location[1] + ')' : '');
            }
            return 'Uncaught ' + describe(error);
        } catch {
            return 'Transform threw an unprintable value';
        }
    };

    const state = { status: 'pending' };
    let registered;
    let registeredTransform;
    const fail = (message) => {
        if (state.status !== 'pending') return;
        state.status = 'error';
        state.error = message;
    };
    /* run() wins; transform() is the deprecated TRMNL name and is only used without a run(). */
    const pick = () => {
        if (typeof registered === 'function') return registered;
        const exported = globals.module && globals.module.exports;
        if (exported && typeof exported.run === 'function') return exported.run;
        if (typeof exported === 'function') return exported;
        if (typeof registeredTransform === 'function') return registeredTransform;
        if (exported && typeof exported.transform === 'function') return exported.transform;
        return undefined;
    };
    const settle = (value) => {
        if (state.status !== 'pending') return;
        try {
            if (value === null || typeof value !== 'object') {
                fail('Transform must return an object or array');
                return;
            }
            state.json = JSONStringify(value);
            state.status = 'ok';
        } catch (error) {
            fail('run() must return a JSON-serializable value: ' + formatError(error));
        }
    };

    /* --- binary buffers: budgeted, because their memory lives outside the V8 heap limit --- */

    for (const name of ['WebAssembly', 'SharedArrayBuffer', 'Atomics']) {
        delete globals[name];
    }

    let bufferBytes = 0;
    const charge = (bytes) => {
        bufferBytes += bytes;
        if (!(bufferBytes <= options.bufferLimitBytes)) {
            throw new RangeError('Transform exceeded the binary buffer limit');
        }
    };
    const toIndex = (value) => {
        const number = value === undefined ? 0 : MathTrunc(NumberCtor(value));
        if (number !== number) return 0;
        if (!(number >= 0 && number <= 9007199254740991)) throw new RangeError('Invalid length');
        return number;
    };

    const OriginalArrayBuffer = ArrayBuffer;
    const ArrayBufferPrototype = OriginalArrayBuffer.prototype;
    const arrayBufferByteLength = getOwnPropertyDescriptor(ArrayBufferPrototype, 'byteLength').get;
    const isArrayBuffer = (value) => {
        try {
            apply(arrayBufferByteLength, value, []);
            return true;
        } catch {
            return false;
        }
    };

    const budgeted = (Original, allocate) => {
        const wrapper = new ProxyCtor(Original, {
            construct(target, args, newTarget) {
                return construct(target, allocate(args), newTarget === wrapper ? target : newTarget);
            },
        });
        defineProperty(Original.prototype, 'constructor', { value: wrapper, writable: true, configurable: true, enumerable: false });
        return wrapper;
    };

    expose('ArrayBuffer', budgeted(OriginalArrayBuffer, (args) => {
        const length = toIndex(args[0]);
        const settings = args[1];
        const maxByteLength = settings !== null && typeof settings === 'object' && settings.maxByteLength !== undefined
            ? toIndex(settings.maxByteLength)
            : undefined;
        charge(maxByteLength === undefined ? length : MathMax(length, maxByteLength));
        return maxByteLength === undefined ? [length] : [length, { maxByteLength }];
    }));

    const originalBufferSlice = ArrayBufferPrototype.slice;
    ArrayBufferPrototype.slice = function slice(...args) {
        charge(apply(arrayBufferByteLength, this, []));
        return apply(originalBufferSlice, this, args);
    };
    for (const name of ['transfer', 'transferToFixedLength']) {
        const original = ArrayBufferPrototype[name];
        if (typeof original !== 'function') continue;
        ArrayBufferPrototype[name] = function (newLength) {
            const length = newLength === undefined ? apply(arrayBufferByteLength, this, []) : toIndex(newLength);
            charge(length);
            return apply(original, this, [length]);
        };
    }

    const TYPED_ARRAYS = ['Int8Array', 'Uint8Array', 'Uint8ClampedArray', 'Int16Array', 'Uint16Array', 'Int32Array',
        'Uint32Array', 'Float16Array', 'Float32Array', 'Float64Array', 'BigInt64Array', 'BigUint64Array'];
    const TypedArrayPrototype = getPrototypeOf(Uint8Array.prototype);
    const typedArrayByteLength = getOwnPropertyDescriptor(TypedArrayPrototype, 'byteLength').get;

    // Copying methods may allocate through the intrinsic constructor (species fallback, change-by-copy).
    for (const name of ['slice', 'map', 'filter', 'toReversed', 'toSorted', 'with']) {
        const original = TypedArrayPrototype[name];
        if (typeof original !== 'function') continue;
        TypedArrayPrototype[name] = function (...args) {
            charge(apply(typedArrayByteLength, this, []));
            return apply(original, this, args);
        };
    }
    // These allocate through %Uint8Array% directly, bypassing any constructor wrapper.
    delete Uint8Array.fromBase64;
    delete Uint8Array.fromHex;

    for (const name of TYPED_ARRAYS) {
        const Original = globals[name];
        if (typeof Original !== 'function') continue;
        const bytesPerElement = Original.BYTES_PER_ELEMENT;
        expose(name, budgeted(Original, (args) => {
            const source = args[0];
            if (source === null || typeof source !== 'object') {
                const length = toIndex(source);
                charge(length * bytesPerElement);
                return [length];
            }
            if (isArrayBuffer(source)) {
                return [source, args[1], args[2]];
            }
            // Fail early on an honest oversized array-like; one that lies about its length is still capped by the heap.
            const declaredLength = NumberCtor(source.length);
            if (declaredLength * bytesPerElement > options.bufferLimitBytes - bufferBytes) {
                charge(declaredLength * bytesPerElement);
            }
            const values = apply(ArrayFrom, ArrayCtor, [source]);
            charge(values.length * bytesPerElement);
            return [values];
        }));
    }
    const Bytes = globals.Uint8Array;

    /* --- DOMException, AbortController, AbortSignal --- */

    const DOM_EXCEPTION_CODES = { IndexSizeError: 1, NotFoundError: 8, NotSupportedError: 9, InvalidStateError: 11, SyntaxError: 12, InvalidCharacterError: 5, NetworkError: 19, AbortError: 20, TimeoutError: 23, DataCloneError: 25 };
    class DOMException extends Error {
        constructor(message = '', name = 'Error') {
            super(StringCtor(message));
            defineProperty(this, 'name', { value: StringCtor(name), writable: true, configurable: true, enumerable: false });
        }
        get code() { return DOM_EXCEPTION_CODES[this.name] ?? 0; }
    }

    let abortSignal;
    let createSignal;
    const SIGNAL_KEY = {};
    class AbortSignal {
        #aborted = false;
        #reason = undefined;
        #listeners = [];
        constructor(key) {
            if (key !== SIGNAL_KEY) throw new TypeError('Illegal constructor');
            this.onabort = null;
        }
        get aborted() { return this.#aborted; }
        get reason() { return this.#reason; }
        throwIfAborted() { if (this.#aborted) throw this.#reason; }
        addEventListener(type, listener, settings) {
            if (type !== 'abort' || listener === null || listener === undefined) return;
            if (this.#listeners.some((entry) => entry.listener === listener)) return;
            this.#listeners.push({ listener, once: !!(settings && typeof settings === 'object' && settings.once) });
        }
        removeEventListener(type, listener) {
            if (type !== 'abort') return;
            this.#listeners = this.#listeners.filter((entry) => entry.listener !== listener);
        }
        dispatchEvent(event) {
            for (const entry of [...this.#listeners]) {
                if (entry.once) this.removeEventListener('abort', entry.listener);
                try {
                    if (typeof entry.listener === 'function') entry.listener.call(this, event);
                    else if (entry.listener && typeof entry.listener.handleEvent === 'function') entry.listener.handleEvent(event);
                } catch (error) {
                    log('Uncaught in abort listener: ' + formatError(error));
                }
            }
            return true;
        }
        static abort(reason) {
            const signal = createSignal();
            abortSignal(signal, reason);
            return signal;
        }
        static timeout(milliseconds) {
            const signal = createSignal();
            globals.setTimeout(() => abortSignal(signal, new DOMException('The operation was aborted due to timeout', 'TimeoutError')), milliseconds);
            return signal;
        }
        static any(signals) {
            const signal = createSignal();
            for (const source of signals) {
                if (source.aborted) {
                    abortSignal(signal, source.reason);
                    return signal;
                }
            }
            for (const source of signals) {
                source.addEventListener('abort', () => abortSignal(signal, source.reason), { once: true });
            }
            return signal;
        }
        static {
            createSignal = () => new AbortSignal(SIGNAL_KEY);
            abortSignal = (signal, reason) => {
                if (signal.#aborted) return;
                signal.#aborted = true;
                signal.#reason = reason === undefined ? new DOMException('This operation was aborted', 'AbortError') : reason;
                const event = freeze({ type: 'abort', target: signal, currentTarget: signal });
                if (typeof signal.onabort === 'function') {
                    try { signal.onabort(event); } catch (error) { log('Uncaught in onabort: ' + formatError(error)); }
                }
                signal.dispatchEvent(event);
            };
        }
    }

    class AbortController {
        #signal = createSignal();
        get signal() { return this.#signal; }
        abort(reason) { abortSignal(this.#signal, reason); }
    }

    /* --- timers: due times are real time; the runner sleeps until the next one within the budget --- */

    const timers = new MapCtor();
    let nextTimerId = 1;
    const schedule = (callback, delay, args, repeat) => {
        if (typeof callback !== 'function') {
            throw new TypeError('The "callback" argument must be of type function');
        }
        const milliseconds = MathMax(repeat ? 1 : 0, NumberCtor(delay) || 0);
        const id = nextTimerId++;
        timers.set(id, { callback, args, milliseconds, repeat, due: DateNow() + milliseconds });
        return id;
    };
    const clearTimer = (id) => {
        timers.delete(NumberCtor(id));
    };
    const nextTimer = () => {
        let next = null;
        for (const [id, timer] of timers) {
            if (next === null || timer.due < next.timer.due) next = { id, timer };
        }
        return next;
    };

    /* --- text encoding --- */

    class TextEncoder {
        get encoding() { return 'utf-8'; }
        encode(input = '') {
            const text = StringCtor(input);
            const bytes = [];
            for (let index = 0; index < text.length; index++) {
                let point = text.charCodeAt(index);
                if (point >= 0xD800 && point <= 0xDBFF && index + 1 < text.length) {
                    const low = text.charCodeAt(index + 1);
                    if (low >= 0xDC00 && low <= 0xDFFF) {
                        point = 0x10000 + ((point - 0xD800) << 10) + (low - 0xDC00);
                        index++;
                    } else {
                        point = 0xFFFD;
                    }
                } else if (point >= 0xD800 && point <= 0xDFFF) {
                    point = 0xFFFD;
                }
                if (point < 0x80) {
                    bytes.push(point);
                } else if (point < 0x800) {
                    bytes.push(0xC0 | (point >> 6), 0x80 | (point & 0x3F));
                } else if (point < 0x10000) {
                    bytes.push(0xE0 | (point >> 12), 0x80 | ((point >> 6) & 0x3F), 0x80 | (point & 0x3F));
                } else {
                    bytes.push(0xF0 | (point >> 18), 0x80 | ((point >> 12) & 0x3F), 0x80 | ((point >> 6) & 0x3F), 0x80 | (point & 0x3F));
                }
            }
            return new Bytes(bytes);
        }
        encodeInto(source, destination) {
            const encoded = this.encode(source);
            const text = StringCtor(source);
            let written = 0;
            let read = 0;
            for (let index = 0; index < text.length; index++) {
                const point = text.codePointAt(index);
                const size = point < 0x80 ? 1 : point < 0x800 ? 2 : point < 0x10000 ? 3 : 4;
                if (written + size > destination.length) break;
                for (let offset = 0; offset < size; offset++) destination[written + offset] = encoded[written + offset];
                written += size;
                const units = point > 0xFFFF ? 2 : 1;
                read += units;
                index += units - 1;
            }
            return { read, written };
        }
    }

    const UTF8_LABELS = ['utf-8', 'utf8', 'unicode-1-1-utf-8', 'unicode11utf8', 'unicode20utf8', 'x-unicode20utf8'];
    class TextDecoder {
        #fatal;
        #ignoreBOM;
        #needed = 0;
        #seen = 0;
        #point = 0;
        #lower = 0x80;
        #upper = 0xBF;
        #bomChecked = false;
        constructor(label = 'utf-8', settings = {}) {
            if (!UTF8_LABELS.includes(StringCtor(label).trim().toLowerCase())) {
                throw new RangeError('The "' + StringCtor(label) + '" encoding is not supported in transforms');
            }
            this.#fatal = !!(settings && settings.fatal);
            this.#ignoreBOM = !!(settings && settings.ignoreBOM);
        }
        get encoding() { return 'utf-8'; }
        get fatal() { return this.#fatal; }
        get ignoreBOM() { return this.#ignoreBOM; }
        decode(input, settings = {}) {
            const bytes = input === undefined || input === null ? new Bytes(0)
                : isArrayBuffer(input) ? new Bytes(input)
                : new Bytes(input.buffer, input.byteOffset, input.byteLength);
            const units = [];
            let text = '';
            const flush = () => {
                text += apply(StringFromCharCode, null, units);
                units.length = 0;
            };
            const emit = (point) => {
                if (!this.#bomChecked) {
                    this.#bomChecked = true;
                    if (point === 0xFEFF && !this.#ignoreBOM) return;
                }
                if (point > 0xFFFF) {
                    point -= 0x10000;
                    units.push(0xD800 + (point >> 10), 0xDC00 + (point & 0x3FF));
                } else {
                    units.push(point);
                }
                if (units.length >= 8192) flush();
            };
            const reset = () => {
                this.#needed = 0;
                this.#seen = 0;
                this.#point = 0;
                this.#lower = 0x80;
                this.#upper = 0xBF;
            };
            const invalid = () => {
                if (this.#fatal) {
                    reset();
                    this.#bomChecked = false;
                    throw new TypeError('The encoded data was not valid for encoding utf-8');
                }
                emit(0xFFFD);
            };
            for (let index = 0; index < bytes.length; index++) {
                const byte = bytes[index];
                if (this.#needed === 0) {
                    if (byte <= 0x7F) {
                        emit(byte);
                    } else if (byte >= 0xC2 && byte <= 0xDF) {
                        this.#needed = 1;
                        this.#point = byte & 0x1F;
                    } else if (byte >= 0xE0 && byte <= 0xEF) {
                        if (byte === 0xE0) this.#lower = 0xA0;
                        if (byte === 0xED) this.#upper = 0x9F;
                        this.#needed = 2;
                        this.#point = byte & 0x0F;
                    } else if (byte >= 0xF0 && byte <= 0xF4) {
                        if (byte === 0xF0) this.#lower = 0x90;
                        if (byte === 0xF4) this.#upper = 0x8F;
                        this.#needed = 3;
                        this.#point = byte & 0x07;
                    } else {
                        invalid();
                    }
                    continue;
                }
                if (byte < this.#lower || byte > this.#upper) {
                    reset();
                    invalid();
                    index--;
                    continue;
                }
                this.#lower = 0x80;
                this.#upper = 0xBF;
                this.#point = (this.#point << 6) | (byte & 0x3F);
                this.#seen++;
                if (this.#seen === this.#needed) {
                    const point = this.#point;
                    reset();
                    emit(point);
                }
            }
            const streaming = !!(settings && settings.stream);
            if (!streaming) {
                if (this.#needed !== 0) {
                    reset();
                    invalid();
                }
                this.#bomChecked = false;
            }
            flush();
            return text;
        }
    }

    /* --- base64 --- */

    const BASE64 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    const btoa = (input) => {
        const text = StringCtor(input);
        let output = '';
        for (let index = 0; index < text.length; index += 3) {
            const a = text.charCodeAt(index);
            const b = text.charCodeAt(index + 1);
            const c = text.charCodeAt(index + 2);
            if (a > 0xFF || b > 0xFF || c > 0xFF) {
                throw new DOMException('Invalid character', 'InvalidCharacterError');
            }
            const triple = (a << 16) | ((b || 0) << 8) | (c || 0);
            output += BASE64[(triple >> 18) & 63] + BASE64[(triple >> 12) & 63]
                + (index + 1 < text.length ? BASE64[(triple >> 6) & 63] : '=')
                + (index + 2 < text.length ? BASE64[triple & 63] : '=');
        }
        return output;
    };
    const atob = (input) => {
        let text = StringCtor(input).replace(/[\t\n\f\r ]/g, '');
        if (text.length % 4 === 0) text = text.replace(/==?$/, '');
        if (text.length % 4 === 1 || /[^A-Za-z0-9+/]/.test(text)) {
            throw new DOMException('The string to be decoded is not correctly encoded.', 'InvalidCharacterError');
        }
        let output = '';
        let buffer = 0;
        let bits = 0;
        for (const character of text) {
            buffer = (buffer << 6) | BASE64.indexOf(character);
            bits += 6;
            if (bits >= 8) {
                bits -= 8;
                output += StringFromCharCode((buffer >> bits) & 0xFF);
            }
        }
        return output;
    };

    /* --- URL, URLSearchParams --- */

    const HEX = '0123456789ABCDEF';
    const utf8Decoder = new TextDecoder();
    const percentByte = (byte) => '%' + HEX[byte >> 4] + HEX[byte & 15];
    const utf8Bytes = (point) => {
        if (point >= 0xD800 && point <= 0xDFFF) point = 0xFFFD;
        if (point < 0x80) return [point];
        if (point < 0x800) return [0xC0 | (point >> 6), 0x80 | (point & 0x3F)];
        if (point < 0x10000) return [0xE0 | (point >> 12), 0x80 | ((point >> 6) & 0x3F), 0x80 | (point & 0x3F)];
        return [0xF0 | (point >> 18), 0x80 | ((point >> 12) & 0x3F), 0x80 | ((point >> 6) & 0x3F), 0x80 | (point & 0x3F)];
    };

    /**
     * Percent-encode every code point outside printable ASCII, plus the ASCII characters in `extra`.
     * Existing `%` escapes are kept as they are, as the URL standard does.
     */
    const percentEncode = (text, extra) => {
        let output = '';
        for (const character of StringCtor(text)) {
            const point = character.codePointAt(0);
            if (point > 0x20 && point < 0x7F && !extra.includes(character)) {
                output += character;
            } else {
                for (const byte of utf8Bytes(point)) output += percentByte(byte);
            }
        }
        return output;
    };

    const percentDecode = (text) => {
        if (!text.includes('%')) return text;
        let output = '';
        let bytes = [];
        const flush = () => {
            if (bytes.length === 0) return;
            output += utf8Decoder.decode(new Bytes(bytes));
            bytes = [];
        };
        for (let index = 0; index < text.length; index++) {
            const pair = text.slice(index + 1, index + 3);
            if (text[index] === '%' && /^[0-9A-Fa-f]{2}$/.test(pair)) {
                bytes.push(NumberCtor.parseInt(pair, 16));
                index += 2;
            } else {
                flush();
                output += text[index];
            }
        }
        flush();
        return output;
    };

    const formEncode = (text) => {
        let output = '';
        for (const character of StringCtor(text)) {
            if (character === ' ') {
                output += '+';
            } else if (/^[A-Za-z0-9*\-._]$/.test(character)) {
                output += character;
            } else {
                for (const byte of utf8Bytes(character.codePointAt(0))) output += percentByte(byte);
            }
        }
        return output;
    };
    const formDecode = (text) => percentDecode(text.replace(/\+/g, ' '));
    const formParse = (query) => {
        const list = [];
        for (const part of StringCtor(query).replace(/^\?/, '').split('&')) {
            if (part === '') continue;
            const equals = part.indexOf('=');
            list.push(equals === -1 ? [formDecode(part), ''] : [formDecode(part.slice(0, equals)), formDecode(part.slice(equals + 1))]);
        }
        return list;
    };

    let attachParams;
    let replaceParams;
    let syncQueryFromParams;

    class URLSearchParams {
        #list = [];
        #url = null;
        constructor(init = undefined) {
            if (init === undefined || init === null) return;
            if (typeof init === 'object' && #list in init) {
                this.#list = init.#list.map((pair) => [pair[0], pair[1]]);
            } else if (typeof init === 'object' || typeof init === 'function') {
                if (typeof init[Symbol.iterator] === 'function') {
                    for (const pair of init) {
                        const entry = [...pair];
                        if (entry.length !== 2) throw new TypeError('Each query pair must be an iterable [name, value] tuple');
                        this.#list.push([StringCtor(entry[0]), StringCtor(entry[1])]);
                    }
                } else {
                    for (const name of Object.keys(init)) this.#list.push([name, StringCtor(init[name])]);
                }
            } else {
                this.#list = formParse(init);
            }
        }
        #update() {
            if (this.#url !== null) syncQueryFromParams(this.#url, this.toString());
        }
        get size() { return this.#list.length; }
        append(name, value) {
            this.#list.push([StringCtor(name), StringCtor(value)]);
            this.#update();
        }
        delete(name, value = undefined) {
            const key = StringCtor(name);
            const match = value === undefined ? null : StringCtor(value);
            this.#list = this.#list.filter((pair) => pair[0] !== key || (match !== null && pair[1] !== match));
            this.#update();
        }
        get(name) {
            const key = StringCtor(name);
            const pair = this.#list.find((entry) => entry[0] === key);
            return pair === undefined ? null : pair[1];
        }
        getAll(name) {
            const key = StringCtor(name);
            return this.#list.filter((pair) => pair[0] === key).map((pair) => pair[1]);
        }
        has(name, value = undefined) {
            const key = StringCtor(name);
            const match = value === undefined ? null : StringCtor(value);
            return this.#list.some((pair) => pair[0] === key && (match === null || pair[1] === match));
        }
        set(name, value) {
            const key = StringCtor(name);
            const index = this.#list.findIndex((pair) => pair[0] === key);
            if (index === -1) {
                this.#list.push([key, StringCtor(value)]);
            } else {
                this.#list[index][1] = StringCtor(value);
                this.#list = this.#list.filter((pair, position) => position <= index || pair[0] !== key);
            }
            this.#update();
        }
        sort() {
            this.#list.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
            this.#update();
        }
        forEach(callback, thisArg = undefined) {
            for (const pair of [...this.#list]) callback.call(thisArg, pair[1], pair[0], this);
        }
        *keys() { for (const pair of [...this.#list]) yield pair[0]; }
        *values() { for (const pair of [...this.#list]) yield pair[1]; }
        *entries() { for (const pair of [...this.#list]) yield [pair[0], pair[1]]; }
        [Symbol.iterator]() { return this.entries(); }
        toString() {
            return this.#list.map((pair) => formEncode(pair[0]) + '=' + formEncode(pair[1])).join('&');
        }
        static {
            attachParams = (params, url) => { params.#url = url; };
            replaceParams = (params, query) => { params.#list = query === null ? [] : formParse(query); };
        }
    }

    const SPECIAL_PORTS = { 'ftp': '21', 'http': '80', 'https': '443', 'ws': '80', 'wss': '443', 'file': '' };
    const isSpecial = (scheme) => Object.prototype.hasOwnProperty.call(SPECIAL_PORTS, scheme);
    const PATH_ENCODE = ' "#<>?`{}';
    const USERINFO_ENCODE = PATH_ENCODE + '/:;=@[\\]^|';
    const FRAGMENT_ENCODE = ' "<>`';
    const queryEncodeSet = (scheme) => (isSpecial(scheme) ? ' "#<>\'' : ' "#<>');

    const normalizePath = (path) => {
        const segments = path.split('/');
        const output = [];
        for (let index = 1; index < segments.length; index++) {
            const segment = segments[index].toLowerCase();
            const last = index === segments.length - 1;
            if (segment === '..' || segment === '.%2e' || segment === '%2e.' || segment === '%2e%2e') {
                output.pop();
                if (last) output.push('');
            } else if (segment === '.' || segment === '%2e') {
                if (last) output.push('');
            } else {
                output.push(segments[index]);
            }
        }
        return '/' + output.join('/');
    };

    const splitTail = (rest) => {
        let path = rest;
        let query = null;
        let fragment = null;
        const hash = path.indexOf('#');
        if (hash !== -1) {
            fragment = path.slice(hash + 1);
            path = path.slice(0, hash);
        }
        const question = path.indexOf('?');
        if (question !== -1) {
            query = path.slice(question + 1);
            path = path.slice(0, question);
        }
        return { path, query, fragment };
    };

    /** Special schemes treat `\` like `/` before the query. */
    const slashes = (text, special) => {
        if (!special) return text;
        const end = text.search(/[?#]/);
        return end === -1 ? text.replace(/\\/g, '/') : text.slice(0, end).replace(/\\/g, '/') + text.slice(end);
    };

    const parseAuthority = (scheme, rest) => {
        const special = isSpecial(scheme);
        const end = rest.search(/[/?#]/);
        const authority = end === -1 ? rest : rest.slice(0, end);
        const tail = splitTail(end === -1 ? '' : rest.slice(end));
        let username = '';
        let password = '';
        let hostPort = authority;
        const at = authority.lastIndexOf('@');
        if (at !== -1) {
            const userinfo = authority.slice(0, at);
            hostPort = authority.slice(at + 1);
            const colon = userinfo.indexOf(':');
            username = percentEncode(colon === -1 ? userinfo : userinfo.slice(0, colon), USERINFO_ENCODE);
            password = colon === -1 ? '' : percentEncode(userinfo.slice(colon + 1), USERINFO_ENCODE);
        }
        let hostname = hostPort;
        let port = '';
        const portMatch = /^(\[[^\]]*\]|[^:]*)(?::(.*))?$/.exec(hostPort);
        if (portMatch === null) return null;
        hostname = portMatch[1];
        if (portMatch[2] !== undefined && portMatch[2] !== '') {
            if (!/^\d+$/.test(portMatch[2]) || NumberCtor(portMatch[2]) > 65535) return null;
            port = StringCtor(NumberCtor(portMatch[2]));
        }
        if (special) {
            hostname = percentDecode(hostname).toLowerCase();
            if (hostname === '' && scheme !== 'file') return null;
            if (port === SPECIAL_PORTS[scheme]) port = '';
        }
        if (!hostname.startsWith('[') && /[\u0000- #/:<>?@[\\\]^|]/.test(hostname)) return null;
        if (!special && hostname === '' && (username !== '' || password !== '' || port !== '')) return null;
        let pathname = tail.path === '' ? (special ? '/' : '') : normalizePath(tail.path);
        return { scheme, username, password, hostname, port, hasAuthority: true, opaque: false, pathname, query: tail.query, fragment: tail.fragment };
    };

    const encodeRecord = (record) => {
        if (record === null) return null;
        record.pathname = percentEncode(record.pathname, record.opaque ? '' : PATH_ENCODE);
        if (record.query !== null) record.query = percentEncode(record.query, queryEncodeSet(record.scheme));
        if (record.fragment !== null) record.fragment = percentEncode(record.fragment, FRAGMENT_ENCODE);
        return record;
    };

    const parseUrl = (input, base) => {
        const text = StringCtor(input).replace(/^[\u0000- ]+|[\u0000- ]+$/g, '').replace(/[\t\n\r]/g, '');
        const schemeMatch = /^([A-Za-z][A-Za-z0-9+.-]*):/.exec(text);
        if (schemeMatch !== null) {
            const scheme = schemeMatch[1].toLowerCase();
            const special = isSpecial(scheme);
            const rest = slashes(text.slice(schemeMatch[0].length), special);
            if (special && scheme !== 'file') return encodeRecord(parseAuthority(scheme, rest.replace(/^\/*/, '')));
            if (rest.startsWith('//')) return encodeRecord(parseAuthority(scheme, rest.slice(2)));
            const tail = splitTail(rest);
            const hierarchical = tail.path.startsWith('/');
            return encodeRecord({
                scheme, username: '', password: '', hostname: '', port: '',
                hasAuthority: scheme === 'file', opaque: !hierarchical,
                pathname: hierarchical ? normalizePath(tail.path) : tail.path,
                query: tail.query, fragment: tail.fragment,
            });
        }
        if (base === null) return null;
        if (base.opaque && !text.startsWith('#')) return null;
        const relative = slashes(text, isSpecial(base.scheme));
        if (relative.startsWith('//')) return encodeRecord(parseAuthority(base.scheme, relative.slice(2)));
        const tail = splitTail(relative);
        const record = { ...base, query: tail.query, fragment: tail.fragment };
        if (tail.path === '') {
            if (tail.query === null) record.query = base.query;
        } else if (tail.path.startsWith('/')) {
            record.pathname = normalizePath(tail.path);
        } else {
            const directory = base.pathname.slice(0, base.pathname.lastIndexOf('/') + 1) || '/';
            record.pathname = normalizePath(directory + tail.path);
        }
        return encodeRecord(record);
    };

    /**
     * The URL class of the web platform for transforms. Parsing follows the URL standard for the
     * usual cases (absolute and relative URLs, dot segments, default ports, percent-encoding);
     * internationalized host names are not converted to punycode and IP addresses are not normalized.
     */
    class URL {
        #record;
        #searchParams;
        constructor(url, base = undefined) {
            const baseRecord = base === undefined ? null : parseUrl(base, null);
            if (base !== undefined && baseRecord === null) throw new TypeError('Invalid base URL: ' + StringCtor(base));
            const record = parseUrl(url, baseRecord);
            if (record === null) throw new TypeError('Invalid URL: ' + StringCtor(url));
            this.#record = record;
            this.#searchParams = new URLSearchParams(record.query ?? '');
            attachParams(this.#searchParams, this);
        }
        static canParse(url, base = undefined) {
            try {
                new URL(url, base);
                return true;
            } catch {
                return false;
            }
        }
        static parse(url, base = undefined) {
            try {
                return new URL(url, base);
            } catch {
                return null;
            }
        }
        #replace(href) {
            const record = parseUrl(href, null);
            if (record === null) return;
            this.#record = record;
            replaceParams(this.#searchParams, record.query);
        }
        #authority() {
            const record = this.#record;
            if (!record.hasAuthority) return '';
            const credentials = record.username !== '' || record.password !== ''
                ? record.username + (record.password !== '' ? ':' + record.password : '') + '@'
                : '';
            return '//' + credentials + record.hostname + (record.port !== '' ? ':' + record.port : '');
        }
        #withoutAuthority() {
            return this.pathname + this.search + this.hash;
        }
        get href() {
            const record = this.#record;
            return record.scheme + ':' + this.#authority() + record.pathname
                + (record.query !== null ? '?' + record.query : '')
                + (record.fragment !== null ? '#' + record.fragment : '');
        }
        set href(value) {
            const record = parseUrl(value, null);
            if (record === null) throw new TypeError('Invalid URL: ' + StringCtor(value));
            this.#record = record;
            replaceParams(this.#searchParams, record.query);
        }
        get origin() {
            const record = this.#record;
            return isSpecial(record.scheme) && record.scheme !== 'file' ? record.scheme + '://' + this.host : 'null';
        }
        get protocol() { return this.#record.scheme + ':'; }
        set protocol(value) {
            const scheme = StringCtor(value).replace(/:.*$/, '').toLowerCase();
            if (!/^[a-z][a-z0-9+.-]*$/.test(scheme) || isSpecial(scheme) !== isSpecial(this.#record.scheme)) return;
            this.#replace(scheme + ':' + this.#authority() + this.#withoutAuthority());
        }
        get username() { return this.#record.username; }
        set username(value) {
            if (this.#record.hostname === '' || this.#record.scheme === 'file') return;
            this.#record.username = percentEncode(value, USERINFO_ENCODE);
        }
        get password() { return this.#record.password; }
        set password(value) {
            if (this.#record.hostname === '' || this.#record.scheme === 'file') return;
            this.#record.password = percentEncode(value, USERINFO_ENCODE);
        }
        get host() { return this.#record.hostname + (this.#record.port !== '' ? ':' + this.#record.port : ''); }
        set host(value) {
            if (this.#record.opaque) return;
            const record = this.#record;
            const credentials = record.username !== '' || record.password !== ''
                ? record.username + (record.password !== '' ? ':' + record.password : '') + '@'
                : '';
            const host = StringCtor(value).split(/[/?#]/)[0];
            this.#replace(record.scheme + '://' + credentials + host + this.#withoutAuthority());
        }
        get hostname() { return this.#record.hostname; }
        set hostname(value) {
            if (this.#record.opaque) return;
            const port = this.#record.port;
            this.host = StringCtor(value).split(/[:/?#]/)[0] + (port !== '' ? ':' + port : '');
        }
        get port() { return this.#record.port; }
        set port(value) {
            const record = this.#record;
            if (record.hostname === '' || record.scheme === 'file') return;
            const digits = StringCtor(value).match(/^\d*/)[0];
            if (digits === '' && StringCtor(value) !== '') return;
            if (digits !== '' && NumberCtor(digits) > 65535) return;
            const port = digits === '' ? '' : StringCtor(NumberCtor(digits));
            record.port = port === SPECIAL_PORTS[record.scheme] ? '' : port;
        }
        get pathname() { return this.#record.pathname; }
        set pathname(value) {
            const record = this.#record;
            if (record.opaque) return;
            let path = slashes(StringCtor(value), isSpecial(record.scheme));
            if (!path.startsWith('/') && (path !== '' || isSpecial(record.scheme))) path = '/' + path;
            record.pathname = percentEncode(path === '' ? '' : normalizePath(path), PATH_ENCODE);
        }
        get search() {
            const query = this.#record.query;
            return query === null || query === '' ? '' : '?' + query;
        }
        set search(value) {
            const query = StringCtor(value).replace(/^\?/, '');
            this.#record.query = query === '' ? null : percentEncode(query, queryEncodeSet(this.#record.scheme));
            replaceParams(this.#searchParams, this.#record.query);
        }
        get searchParams() { return this.#searchParams; }
        get hash() {
            const fragment = this.#record.fragment;
            return fragment === null || fragment === '' ? '' : '#' + fragment;
        }
        set hash(value) {
            const fragment = StringCtor(value).replace(/^#/, '');
            this.#record.fragment = fragment === '' ? null : percentEncode(fragment, FRAGMENT_ENCODE);
        }
        toString() { return this.href; }
        toJSON() { return this.href; }
        static {
            syncQueryFromParams = (url, serialized) => { url.#record.query = serialized === '' ? null : serialized; };
        }
    }

    /* --- fetch(): requests are queued here and performed by the host (PHP), one batch at a time --- */

    class Headers {
        #entries = new MapCtor();
        constructor(init) {
            if (init === undefined || init === null) return;
            const pairs = init instanceof Headers ? [...init]
                : typeof init[Symbol.iterator] === 'function' ? [...init]
                : typeof init === 'object' ? Object.keys(init).map((name) => [name, init[name]])
                : [];
            for (const pair of pairs) this.append(pair[0], pair[1]);
        }
        append(name, value) {
            const key = StringCtor(name).toLowerCase();
            const existing = this.#entries.get(key);
            this.#entries.set(key, existing === undefined ? StringCtor(value) : existing + ', ' + StringCtor(value));
        }
        set(name, value) { this.#entries.set(StringCtor(name).toLowerCase(), StringCtor(value)); }
        get(name) { const value = this.#entries.get(StringCtor(name).toLowerCase()); return value === undefined ? null : value; }
        has(name) { return this.#entries.has(StringCtor(name).toLowerCase()); }
        delete(name) { this.#entries.delete(StringCtor(name).toLowerCase()); }
        forEach(callback, thisArg) { for (const [name, value] of this.#entries) callback.call(thisArg, value, name, this); }
        keys() { return this.#entries.keys(); }
        values() { return this.#entries.values(); }
        entries() { return this.#entries.entries(); }
        [Symbol.iterator]() { return this.#entries.entries(); }
    }

    const BINARY_UNSUPPORTED = 'Binary response bodies are not available in transforms; use text() or json()';
    class Response {
        #body;
        #used = false;
        constructor(body = null, init = {}) {
            this.#body = body === null || body === undefined ? '' : StringCtor(body);
            this.status = init.status ?? 200;
            this.statusText = init.statusText ?? '';
            this.headers = new Headers(init.headers);
            this.ok = this.status >= 200 && this.status < 300;
            this.url = '';
            this.redirected = false;
            this.type = 'basic';
            this.body = null;
        }
        get bodyUsed() { return this.#used; }
        async text() {
            if (this.#used) throw new TypeError('Body has already been consumed.');
            this.#used = true;
            return this.#body;
        }
        async json() { return JSONParse(await this.text()); }
        async arrayBuffer() { throw new TypeError(BINARY_UNSUPPORTED); }
        async bytes() { throw new TypeError(BINARY_UNSUPPORTED); }
        async blob() { throw new TypeError(BINARY_UNSUPPORTED); }
        clone() {
            if (this.#used) throw new TypeError('Response body is already used');
            const copy = new Response(this.#body, { status: this.status, statusText: this.statusText, headers: this.headers });
            copy.url = this.url;
            return copy;
        }
    }

    const outbox = [];
    const waiting = new MapCtor();
    let nextRequestId = 1;

    const fetch = (resource, init = {}) => {
        try {
            if (!options.allowNetwork) throw new TypeError('Network access is disabled for transforms');
            const settings = init ?? {};
            const signal = settings.signal;
            if (signal && signal.aborted) return PromiseCtor.reject(signal.reason);
            const body = settings.body;
            if (body !== undefined && body !== null && typeof body !== 'string') {
                throw new TypeError('Only string request bodies are supported in transforms; use JSON.stringify()');
            }
            const request = {
                id: nextRequestId++,
                url: StringCtor(resource && typeof resource === 'object' && 'url' in resource ? resource.url : resource),
                method: StringCtor(settings.method ?? 'GET').toUpperCase(),
                headers: [...new Headers(settings.headers)],
                body: body ?? null,
            };
            return new PromiseCtor((resolve, reject) => {
                waiting.set(request.id, { resolve, reject });
                outbox.push(request);
                if (signal && typeof signal.addEventListener === 'function') {
                    signal.addEventListener('abort', () => {
                        if (!waiting.has(request.id)) return;
                        waiting.delete(request.id);
                        const queued = outbox.indexOf(request);
                        if (queued !== -1) outbox.splice(queued, 1);
                        reject(signal.reason);
                    }, { once: true });
                }
            });
        } catch (error) {
            return PromiseCtor.reject(error);
        }
    };

    /* --- globals --- */

    expose('fetch', fetch);
    expose('Headers', Headers);
    expose('Response', Response);
    expose('DOMException', DOMException);
    expose('AbortController', AbortController);
    expose('AbortSignal', AbortSignal);
    expose('TextEncoder', TextEncoder);
    expose('TextDecoder', TextDecoder);
    expose('URL', URL);
    expose('URLSearchParams', URLSearchParams);
    expose('atob', atob);
    expose('btoa', btoa);
    expose('setTimeout', (callback, delay, ...args) => schedule(callback, delay, args, false));
    expose('setInterval', (callback, delay, ...args) => schedule(callback, delay, args, true));
    expose('clearTimeout', clearTimer);
    expose('clearInterval', clearTimer);
    expose('queueMicrotask', (callback) => {
        if (typeof callback !== 'function') throw new TypeError('The "callback" argument must be of type function');
        PromiseCtor.resolve().then(() => callback());
    });
    expose('console', freeze({ log, info: log, warn: log, error: log, debug: log }));
    // Scripts written for TRMNL often end with `if (require.main === module) { ... }` for local testing.
    const require = () => {
        throw new Error('require() is not available in transforms');
    };
    require.main = undefined;
    expose('require', require);
    expose('module', { exports: {} });
    expose('exports', globals.module.exports);

    /* --- entry points called by the host; none of them lets anything thrown by user code escape --- */

    define('__trmnlRegister', (run, transform) => {
        registered = run;
        registeredTransform = transform;
    });
    define('__trmnlFail', (error) => {
        fail(formatError(error));
    });
    define('__trmnlStart', (json) => {
        if (state.status !== 'pending') return;
        try {
            const fn = pick();
            if (fn === undefined) {
                fail('run() is not defined');
                return;
            }
            const input = JSONParse(json);
            (async () => fn(input))().then(settle, (error) => fail(formatError(error)));
        } catch (error) {
            fail(formatError(error));
        }
    });
    define('__trmnlDeliver', (json) => {
        try {
            const message = JSONParse(json);
            for (const answer of ArrayCtor.isArray(message.responses) ? message.responses : []) {
                const pending = waiting.get(answer.id);
                if (pending === undefined) continue;
                waiting.delete(answer.id);
                if (typeof answer.error === 'string') {
                    pending.reject(new TypeError(answer.error));
                    continue;
                }
                const response = new Response(answer.body, { status: answer.status, statusText: answer.statusText, headers: answer.headers });
                response.url = StringCtor(answer.url ?? '');
                pending.resolve(response);
            }
        } catch (error) {
            fail('Transform fetch bridge failed: ' + formatError(error));
        }
    });
    /* Runs the earliest due timer, if any. One per evaluation, so promise jobs settle in between. */
    define('__trmnlTick', () => {
        try {
            const next = nextTimer();
            if (next === null || next.timer.due > DateNow()) return;
            if (next.timer.repeat) {
                next.timer.due = DateNow() + next.timer.milliseconds;
            } else {
                timers.delete(next.id);
            }
            apply(next.timer.callback, globals, next.timer.args);
        } catch (error) {
            fail(formatError(error));
        }
    });
    define('__trmnlCollect', () => {
        const next = nextTimer();
        return JSONStringify({
            status: state.status,
            json: state.json,
            error: state.error,
            logs,
            requests: outbox.splice(0),
            nextTimerDue: next === null ? null : next.timer.due,
        });
    });
}

const BOOTSTRAP = `(${sandboxBootstrap.toString()})(${JSON.stringify({ allowNetwork, bufferLimitBytes })});`;

const context = vm.createContext(Object.create(null), {
    name: 'transform',
    codeGeneration: { strings: false, wasm: false },
    // Drain the context's promise jobs inside each runInContext call, so `timeout` also stops `await` loops.
    microtaskMode: 'afterEvaluate',
});

const TIMEOUT_CODE = 'ERR_SCRIPT_EXECUTION_TIMEOUT';

/**
 * Whether Node aborted an evaluation because it ran past the timeout. Only reads an own data
 * property of a native error, so no code the sandbox attached to the value can run here.
 */
const isTimeout = (value) => !types.isProxy(value)
    && types.isNativeError(value)
    && Object.getOwnPropertyDescriptor(value, 'code')?.value === TIMEOUT_CODE;

const evaluate = (source, filename) => new vm.Script(source, { filename })
    .runInContext(context, { timeout: Math.max(1, Math.min(timeout, deadline - Date.now())) });

/**
 * Hand a string to the sandbox without assigning through a setter the user may have defined.
 */
const provide = (name, value) => {
    Object.defineProperty(context, name, { value, writable: false, configurable: true, enumerable: false });
};

/**
 * Read the sandbox state. Everything arrives as one string and is parsed in this realm,
 * so the values below are plain data owned by the runner.
 */
const collect = () => {
    const result = evaluate('__trmnlCollect()', 'collect.js');
    const parsed = typeof result === 'string' ? JSON.parse(result) : null;

    if (parsed === null || typeof parsed !== 'object') {
        return { status: 'error', error: 'Transform state was tampered with', logs: [], requests: [], nextTimerDue: null };
    }

    return {
        status: String(parsed.status),
        json: typeof parsed.json === 'string' ? parsed.json : undefined,
        error: parsed.error,
        logs: Array.isArray(parsed.logs) ? parsed.logs.filter((line) => typeof line === 'string') : [],
        requests: Array.isArray(parsed.requests) ? parsed.requests : [],
        nextTimerDue: typeof parsed.nextTimerDue === 'number' ? parsed.nextTimerDue : null,
    };
};

const failed = (error, logs = []) => ({ type: 'result', ok: false, error: String(error), logs });
const timedOut = (logs = []) => failed(`Transform timed out after ${timeout} ms`, logs);

/**
 * Turn anything that escaped an evaluation into a result. User code runs inside a `try` in the
 * sandbox, so this only sees Node's own errors (timeouts, syntax errors while compiling). Anything
 * else is reported generically without being inspected.
 */
const failure = (thrown) => {
    if (isTimeout(thrown)) {
        return timedOut();
    }

    if (!types.isProxy(thrown) && thrown instanceof SyntaxError) {
        const line = /transform\.js:(\d+)/.exec(String(thrown.stack ?? ''));

        return failed(`SyntaxError: ${thrown.message}${line ? ` (line ${line[1]})` : ''}`);
    }

    return failed('Transform execution failed');
};

const execute = async (code, input) => {
    if (DYNAMIC_IMPORT.test(code)) {
        return failed('import() is not available in transforms');
    }

    let state;
    try {
        evaluate(BOOTSTRAP, 'bootstrap.js');
        evaluate(toScript(code), 'transform.js');
        provide('__trmnlInput', JSON.stringify(input ?? null));
        evaluate('__trmnlStart(__trmnlInput)', 'start.js');
        state = collect();

        // Serve fetch() batches and due timers until the script settles or has nothing left to wait for.
        while (state.status === 'pending') {
            if (Date.now() >= deadline) {
                return timedOut(state.logs);
            }

            if (state.requests.length > 0) {
                send({ type: 'fetch', requests: state.requests });

                const answer = await nextLine();
                if (answer === undefined) {
                    return failed('Transform fetch bridge closed', state.logs);
                }

                provide('__trmnlDelivery', answer);
                evaluate('__trmnlDeliver(__trmnlDelivery)', 'deliver.js');
            } else if (state.nextTimerDue !== null) {
                if (state.nextTimerDue > deadline) {
                    return timedOut(state.logs);
                }

                const wait = state.nextTimerDue - Date.now();
                if (wait > 0) {
                    await sleep(wait);
                }

                evaluate('__trmnlTick()', 'timer.js');
            } else {
                break;
            }

            state = collect();
        }
    } catch (thrown) {
        return failure(thrown);
    }

    if (state.status === 'pending') {
        return failed('run() returned a promise that never settled', state.logs);
    }

    if (state.status !== 'ok' || state.json === undefined) {
        return failed(state.error ?? 'Transform execution failed', state.logs);
    }

    return { type: 'result', ok: true, output: JSON.parse(state.json), logs: state.logs };
};

let request;
try {
    request = JSON.parse((await nextLine()) ?? '');
} catch {
    request = null;
}

if (request === null || typeof request !== 'object' || typeof request.code !== 'string') {
    finish(failed('Transform request is malformed'));
} else {
    finish(await execute(request.code, request.input));
}
