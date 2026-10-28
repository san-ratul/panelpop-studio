const baseClasses = [
    'inline-flex items-center justify-center gap-2 rounded-lg text-sm font-semibold transition',
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600',
    'disabled:cursor-not-allowed disabled:opacity-50',
].join(' ');

const variants = {
    primary: 'bg-indigo-600 text-white shadow-sm hover:bg-indigo-700',
    secondary: 'border border-gray-300 bg-white text-gray-700 shadow-xs hover:bg-gray-50',
    outline: 'border border-indigo-600 bg-white text-indigo-700 shadow-sm hover:bg-indigo-50',
    danger: 'border border-red-300 bg-white text-red-600 shadow-sm hover:bg-red-50',
    ghost: 'text-gray-600 hover:text-gray-900',
};

const sizes = {
    sm: 'px-3 py-1.5',
    md: 'px-4 py-2',
};

export function buttonVariants({ variant = 'primary', size = 'md' } = {}) {
    return [baseClasses, variants[variant] ?? variants.primary, sizes[size] ?? sizes.md].join(' ');
}
