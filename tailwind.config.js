import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/View/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ink: {
                    50: '#f6f6f6',
                    100: '#e7e7e7',
                    200: '#d1d1d1',
                    300: '#b0b0b0',
                    400: '#888888',
                    500: '#6d6d6d',
                    600: '#5d5d5d',
                    700: '#4f4f4f',
                    800: '#454545',
                    900: '#3d3d3d',
                    950: '#0a0a0a',
                },
            },
            boxShadow: {
                soft: '0 1px 2px 0 rgb(0 0 0 / 0.04), 0 1px 3px 0 rgb(0 0 0 / 0.06)',
                card: '0 1px 3px 0 rgb(0 0 0 / 0.05), 0 8px 24px -12px rgb(0 0 0 / 0.12)',
                lift: '0 12px 32px -12px rgb(0 0 0 / 0.25)',
            },
            keyframes: {
                'fade-in': {
                    '0%': { opacity: '0', transform: 'translateY(4px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'slide-in': {
                    '0%': { opacity: '0', transform: 'translateX(12px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
            },
            animation: {
                'fade-in': 'fade-in .25s ease-out both',
                'slide-in': 'slide-in .3s ease-out both',
            },
        },
    },

    plugins: [forms],
};
