import type { SVGAttributes } from 'react';

/** Marca do VetorFlex: um "V" formado por dois vetores, o da direita apontando para cima (crescimento). */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 40 40"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <path d="M4 8h7.2l9.1 19.2-3.6 7.8L4 8Z" />
            <path d="M22.4 31.6 33.2 9.4l-4.1-.9L37 3l-.6 9.9-2.6-3.1-9.8 21.8h-1.6Z" />
        </svg>
    );
}
