/** Decorative school illustration; colors follow the current accent palette. */
export function EducationIllustration() {
    return (
        <svg viewBox="0 0 200 130" fill="none" aria-hidden="true" focusable="false" className="education-illustration">
            <ellipse cx="104" cy="121" rx="78" ry="7" fill="currentColor" opacity=".08" />
            <g fill="currentColor" opacity=".16">
                <path d="M33 119C6 85 18 64 26 72c9 8 10 27 13 37-6-42 9-59 15-42 5 14-10 38-15 52Z" />
                <path d="M158 119c5-39 15-54 23-46 8 9-11 31-18 40 25-27 37-24 29-12-5 8-21 15-34 18Z" />
                <ellipse cx="30" cy="35" rx="4" ry="7" transform="rotate(-35 30 35)" />
                <ellipse cx="179" cy="45" rx="4" ry="7" transform="rotate(30 179 45)" />
            </g>
            <g stroke="currentColor" strokeWidth="3" strokeLinejoin="round">
                <rect x="60" y="97" width="103" height="23" rx="5" fill="currentColor" fillOpacity=".2" />
                <path d="M68 101h80v15H68a5 5 0 0 1-5-5v-5a5 5 0 0 1 5-5Z" fill="var(--ui-surface)" />
                <rect x="47" y="75" width="105" height="23" rx="5" fill="currentColor" fillOpacity=".12" />
                <path d="M55 79h82v15H55a5 5 0 0 1-5-5v-5a5 5 0 0 1 5-5Z" fill="var(--ui-surface)" />
                <rect x="63" y="53" width="85" height="22" rx="5" fill="currentColor" fillOpacity=".2" />
                <path d="M71 57h64v14H71a5 5 0 0 1-5-5v-4a5 5 0 0 1 5-5Z" fill="var(--ui-surface)" />
            </g>
            <g stroke="currentColor" strokeWidth="2.5" strokeLinejoin="round">
                <path d="M84 26v21c14 8 30 8 43 0V26" fill="currentColor" fillOpacity=".35" />
                <path d="m52 22 55-18 55 18-55 19-55-19Z" fill="currentColor" fillOpacity=".75" />
                <path d="m109 22 39 13v20" strokeLinecap="round" />
                <path d="m148 49-3 10h6l-3-10Z" fill="currentColor" />
            </g>
        </svg>
    );
}
