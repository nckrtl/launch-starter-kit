import type { SVGAttributes } from "react";

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 440 440"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            data-slot="commander-logo"
        >
            <path
                d="M440 351.086L220 439.999L0 351.087V274.819L220 363.732L440 274.819V351.086ZM440 213.678L220 302.59L0 213.678V137.41L220 226.323L440 137.41V213.678ZM440 76.2676L220 165.18L0 76.2676V0H440V76.2676Z"
                fill="currentColor"
            />
        </svg>
    );
}
