/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./public/**/*.html",
  ],
  theme: {
    extend: {
      colors: {
        fuel: {
          50: '#fef9ec',
          100: '#fcf0c9',
          200: '#f9de8e',
          300: '#f5c54d',
          400: '#f2ae24',
          500: '#ec920c',
          600: '#d06c07',
          700: '#ad4b0a',
          800: '#8d3a0e',
          900: '#74300f',
        }
      }
    },
  },
  plugins: [],
}
