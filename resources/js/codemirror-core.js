import { EditorView, lineNumbers, keymap } from '@codemirror/view';
import { ViewPlugin } from '@codemirror/view';
import { indentWithTab, selectAll } from '@codemirror/commands';
import { foldGutter, foldKeymap } from '@codemirror/language';
import { history, historyKeymap } from '@codemirror/commands';
import { searchKeymap } from '@codemirror/search';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { css } from '@codemirror/lang-css';
import { liquid } from '@codemirror/lang-liquid';
import { yaml } from '@codemirror/lang-yaml';
import { python } from '@codemirror/lang-python';
import { php } from '@codemirror/lang-php';
import { oneDark } from '@codemirror/theme-one-dark';
import { githubLight } from '@fsegurai/codemirror-theme-github-light';

// Language support mapping
const LANGUAGE_MAP = {
    'javascript': javascript,
    'js': javascript,
    'json': json,
    'css': css,
    'liquid': liquid,
    'html': html,
    'yaml': yaml,
    'yml': yaml,
    'python': python,
    'py': python,
    'php': php,
};

// Theme support mapping
const THEME_MAP = {
    'light': githubLight,
    'dark': oneDark,
};

/**
 * Get language support based on language parameter
 * @param {string} language - Language name or comma-separated list
 * @returns {Array|Extension} Language extension(s)
 */
function getLanguageSupport(language) {
    // Handle comma-separated languages
    if (language.includes(',')) {
        const languages = language.split(',').map(lang => lang.trim().toLowerCase());
        const languageExtensions = [];

        languages.forEach(lang => {
            const languageFn = LANGUAGE_MAP[lang];
            if (languageFn) {
                languageExtensions.push(languageFn());
            }
        });

        return languageExtensions;
    }

    // Handle single language
    const languageFn = LANGUAGE_MAP[language.toLowerCase()] || LANGUAGE_MAP.html;
    return languageFn();
}

/**
 * Get theme support
 * @param {string} theme - Theme name
 * @returns {Array} Theme extensions
 */
function getThemeSupport(theme) {
    const themeFn = THEME_MAP[theme] || THEME_MAP.light;
    return [themeFn];
}

/**
 * Create a resize plugin that handles container resizing
 * @returns {ViewPlugin} Resize plugin
 */
function createResizePlugin() {
    return ViewPlugin.fromClass(class {
        constructor(view) {
            this.view = view;
            this.resizeObserver = null;
            this.setupResizeObserver();
        }
        
        setupResizeObserver() {
            const container = this.view.dom.parentElement;
            if (container) {
                this.resizeObserver = new ResizeObserver(() => {
                    // Use requestAnimationFrame to ensure proper timing
                    requestAnimationFrame(() => {
                        this.view.requestMeasure();
                    });
                });
                this.resizeObserver.observe(container);
            }
        }
        
        destroy() {
            if (this.resizeObserver) {
                this.resizeObserver.disconnect();
            }
        }
    });
}

/**
 * Get Flux-like theme styling based on theme
 * @param {string} theme - Theme name ('light', 'dark', or 'auto')
 * @returns {Object} Theme-specific styling
 */
function getFluxThemeStyling(theme) {
    const isDark = theme === 'dark' || (theme === 'auto' && getSystemTheme() === 'dark');

    if (isDark) {
        return {
            backgroundColor: 'oklab(0.999994 0.0000455678 0.0000200868 / 0.1)',
            gutterBackgroundColor: 'oklch(26.9% 0 0)',
            borderColor: '#374151',
            focusBorderColor: 'rgb(224 91 68)',
        };
    } else {
        return {
            backgroundColor: '#fff', // zinc-50
            gutterBackgroundColor: '#fafafa', // zinc-50
            borderColor: '#e5e7eb', // gray-200
            focusBorderColor: 'rgb(224 91 68)', // red-500
        };
    }
}

/**
 * Create CodeMirror editor instance
 * @param {HTMLElement} element - DOM element to mount editor
 * @param {Object} options - Editor options
 * @returns {EditorView} CodeMirror editor instance
 */
export function createCodeMirror(element, options = {}) {
    const {
        value = '',
        language = 'html',
        theme = 'light',
        readOnly = false,
        onChange = () => {},
        onUpdate = () => {},
        onBlur = () => {}
    } = options;

    // Get language and theme support
    const languageSupport = getLanguageSupport(language);
    const themeSupport = getThemeSupport(theme);
    const fluxStyling = getFluxThemeStyling(theme);

    // Create editor
    const editor = new EditorView({
        doc: value,
        extensions: [
            lineNumbers(),
            foldGutter(),
            history(), 
            EditorView.lineWrapping, 
            createResizePlugin(),
            ...(Array.isArray(languageSupport) ? languageSupport : [languageSupport]),
            ...themeSupport,
            keymap.of([
                indentWithTab,
                ...foldKeymap,
                ...historyKeymap,
                ...searchKeymap,
                {
                    key: 'Mod-a',
                    run: selectAll,
                },
            ]),
            EditorView.theme({
                '&': {
                    fontSize: '14px',
                    border: `1px solid ${fluxStyling.borderColor}`,
                    borderRadius: '0.375rem',
                    height: '100%',
                    maxHeight: '100%',
                    overflow: 'hidden',
                    backgroundColor: fluxStyling.backgroundColor + ' !important',
                    resize: 'vertical',
                    minHeight: '200px',
                },
                '.cm-gutters': {
                    borderTopLeftRadius: '0.375rem',
                    backgroundColor: fluxStyling.gutterBackgroundColor + ' !important',
                },
                '.cm-gutter': {
                    backgroundColor: fluxStyling.gutterBackgroundColor + ' !important',
                },
                '&.cm-focused': {
                    outline: 'none',
                    borderColor: fluxStyling.focusBorderColor,
                },
                '.cm-content': {
                    padding: '12px',
                },
                '.cm-scroller': {
                    fontFamily: 'ui-monospace, SFMono-Regular, "SF Mono", Monaco, Consolas, "Liberation Mono", "Courier New", monospace',
                    height: '100%',
                    overflow: 'auto',
                },
                '.cm-editor': {
                    height: '100%',
                },
                '.cm-editor .cm-scroller': {
                    height: '100%',
                    overflow: 'auto',
                },
                '.cm-foldGutter': {
                    width: '12px',
                },
                '.cm-foldGutter .cm-gutterElement': {
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    cursor: 'pointer',
                    fontSize: '12px',
                    color: '#6b7280',
                },
                '.cm-foldGutter .cm-gutterElement:hover': {
                    color: '#374151',
                },
                '.cm-foldGutter .cm-gutterElement.cm-folded': {
                    color: '#3b82f6',
                }
            }),
            EditorView.updateListener.of((update) => {
                if (update.docChanged) {
                    const newValue = update.state.doc.toString();
                    onChange(newValue);
                    onUpdate(newValue);
                }
            }),
            EditorView.domEventHandlers({
                blur: onBlur
            }),
            EditorView.editable.of(!readOnly),
        ],
        parent: element
    });

    return editor;
}

/**
 * Auto-detect system theme preference
 * @returns {string} 'dark' or 'light'
 */
export function getSystemTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        return 'dark';
    }
    return 'light';
}

/**
 * Watch for system theme changes
 * @param {Function} callback - Callback function when theme changes
 * @returns {Function} Unwatch function
 */
export function watchThemeChange(callback) {
    if (window.matchMedia) {
        const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        mediaQuery.addEventListener('change', callback);
        return () => mediaQuery.removeEventListener('change', callback);
    }
    return () => {};
}
