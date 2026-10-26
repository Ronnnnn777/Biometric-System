/** @type {import('tailwindcss').Config} */
module.exports = {
  // The portals switch themes through html[data-theme], not the operating
  // system media query, so dark: utilities follow the saved theme attribute.
  darkMode: ['selector', '[data-theme="dark"]'],
  content: [
    './*.php',
    './admin/**/*.php',
    './camera/**/*.php',
    './common/**/*.php',
    './includes/**/*.php',
    './js/**/*.js',
    './teacher/**/*.php',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eff6ff',
          600: '#1f4f91',
          700: '#102e5e',
          950: '#172554',
        },
      },
    },
  },
  plugins: [],
};
