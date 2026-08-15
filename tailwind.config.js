/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./*.{html,php,js}",          // Files in root folder
    "./**/*.{html,php,js}",       // Files in subfolders
    "!./node_modules/**/*",       // EXCLUDE node_modules
    "!./vendor/**/*"              // EXCLUDE PHP vendor folder (if using Composer)
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}