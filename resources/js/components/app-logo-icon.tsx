import type { SVGAttributes } from 'react';

/**
 * Shuja ERP mark — three stacked isometric layers, representing the connected
 * ledger and modules sitting on one core. Single-colour (uses `fill-current`),
 * with opacity giving depth, so it reads on both the gradient tile and plain
 * light/dark backgrounds.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            {/* bottom layer */}
            <path d="M24 23 L42 32 L24 41 L6 32 Z" fill="currentColor" opacity="0.35" />
            {/* middle layer */}
            <path d="M24 15 L42 24 L24 33 L6 24 Z" fill="currentColor" opacity="0.6" />
            {/* top layer */}
            <path d="M24 7 L42 16 L24 25 L6 16 Z" fill="currentColor" />
        </svg>
    );
}
