/**
 * cn — class merge utility (shadcn-vue requirement)
 * ==================================================
 * Combines clsx + tailwind-merge. Required by all shadcn-vue components.
 */

import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}