import eslintPluginVue from 'eslint-plugin-vue'

// One rule with teeth: the one mistake that keeps shipping (territorio's eslint
// lesson: "Guardar never submitted the form" shipped because nothing checked
// identifiers). Style stays off on purpose.
export default [
	...eslintPluginVue.configs['flat/essential'],
	{
		files: ['src/**/*.{js,vue}'],
		// The browser surface the app actually uses. A flat config declares
		// no browser globals of its own — eslint-plugin-vue's flat presets
		// set the sourceType and nothing else — so no-undef needs the surface
		// named: console has been in the app since S5, window and FormData
		// arrive with S8's focus listeners and the planilla upload. Territorio
		// declares the same block (its .eslintrc BROWSER env) for the same
		// reason.
		languageOptions: {
			globals: {
				console: 'readonly',
				window: 'readonly',
				FormData: 'readonly',
				// document: the L2-21 mount tests read teleported dialog bodies
				// through it (NcDialog renders outside the wrapper's tree);
				// URL: payload.test.js's twins fixture path. Both standard, both
				// jsdom/node surfaces the suite runs on.
				document: 'readonly',
				URL: 'readonly',
			},
		},
		rules: {
			'no-undef': 'error',
			// The sidebar mutates its draft prop in place — FeatureDetail's
			// doctrine (the draft is owned by App.vue and edited one record at
			// a time; copying it back and forth would only add a second place
			// for the two to disagree). The essential ruleset reports every
			// deep mutation as no-mutating-props; shallowOnly keeps the rule's
			// real guard — a component reassigning a prop it does not own —
			// and drops the half that fights the doctrine.
			'vue/no-mutating-props': ['error', { shallowOnly: true }],
		},
	},
]
