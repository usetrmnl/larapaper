@if (config('services.trmnl.override_orig_icon'))
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 30 30" {{ $attributes }}>
        <g clip-path="url(#clip0_870_1047)">
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M8.02194 2.11328L15.8863 5.07445L14.4259 8.98481L6.56152 6.02364L8.02194 2.11328Z"
                fill="currentColor"
            />
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M20.6315 1.14647L23.2291 9.16642L19.2738 10.458L16.6761 2.43807L20.6315 1.14647Z"
                fill="currentColor"
            />
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M29.2441 10.4452L24.619 17.4848L21.1471 15.185L25.7723 8.14549L29.2441 10.4452Z"
                fill="currentColor"
            />
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M27.3739 23.0034L19.0088 23.7616L18.6349 19.6023L27 18.8441L27.3739 23.0034Z"
                fill="currentColor"
            />
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M16.4296 29.3638L10.6236 23.2697L13.6292 20.3828L19.4351 26.4769L16.4296 29.3638Z"
                fill="currentColor"
            />
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M4.65232 24.7423L5.77753 16.3849L9.89932 16.9443L8.77411 25.3017L4.65232 24.7423Z"
                fill="currentColor"
            />
            <path
                fill-rule="evenodd"
                clip-rule="evenodd"
                d="M0.910558 12.6074L8.11961 8.28L10.2539 11.8645L3.04481 16.192L0.910558 12.6074Z"
                fill="currentColor"
            />
        </g>
        <defs>
            <clipPath id="clip0_870_1047">
                <rect width="30" height="30" fill="currentColor" />
            </clipPath>
        </defs>
    </svg>
@else
    <svg xmlns="http://www.w3.org/2000/svg" width="1000" height="1000" viewBox="0 0 1000 1000" >
        <defs>
            <clipPath id="canvas">
                <rect width="1000" height="1000" rx="200" ry="200" />
            </clipPath>
        </defs>
        <g clip-path="url(#canvas)">
            <rect width="1000" height="1000" fill="#F8654B" />
            <!-- 4:3 device, bottom bezel -->
            <rect x="150" y="237.5" width="700" height="525" rx="36" fill="currentColor" />
            <rect x="198" y="285.5" width="604" height="399" rx="12" fill="#F8654B" />
            <!-- Pixelify Sans "LP" -->
            <path fill="currentColor" fill-rule="evenodd" d="M524.4 614.66V392.04h36.7v-36.7h113.73v36.7h37.1v112.92h-37.1v36.7h-109.7v73Zm40.73-113.73H670.8V396.07H565.13ZM324.77 614.66v-36.3h-36.7V355.34h40.73v219h105.66v-36.7h41.14v40.72h-37.1v36.3Z"/>
        </g>
    </svg>
@endif
