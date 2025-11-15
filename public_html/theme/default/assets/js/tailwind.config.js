module.exports = {
  content: [
	'*.{html,js,php}',
	'../*.{html,js,php}',
	'../../../views/*.{html,js,php}',
	'../../../views/profile/*.{html,js,php}',
	'../../../includes/*.{html,js,php}',
	'../../../../theme/**/*.{html,js,php}',
	'../../../../theme/**/includes/*.{html,js,php}',
  ],
  presets: [],
  theme: {},
  plugins: [
	require('tailwindcss'),
    require('autoprefixer'),
	require('@tailwindcss/forms'),
	require('@tailwindcss/typography'),
	require('@tailwindcss/aspect-ratio'),
  ],
}
