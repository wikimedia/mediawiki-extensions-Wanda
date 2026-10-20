/* eslint-env node */
// Minimal stand-ins for the Codex components the Wanda SFCs register locally.
// Each renders its named "title" slot (used by CdxAccordion) and its default
// slot so mounted templates produce inspectable markup without pulling in the
// real @wikimedia/codex package.
const slotStub = ( name ) => ( {
	name,
	inheritAttrs: false,
	template: '<div><slot name="title" /><slot /></div>'
} );

module.exports = {
		CdxButton: {
			name: 'CdxButton',
			props: { disabled: Boolean },
			template: '<button :disabled="disabled"><slot /></button>'
		},
	CdxTextArea: slotStub( 'CdxTextArea' ),
	CdxProgressBar: slotStub( 'CdxProgressBar' ),
	CdxCheckbox: slotStub( 'CdxCheckbox' ),
	CdxDialog: {
		name: 'CdxDialog',
		props: { open: Boolean, title: String },
		emits: [ 'update:open' ],
		template: '<div v-if="open" role="dialog"><h2>{{ title }}</h2><slot /><slot name="footer" /></div>'
	},
	CdxTextInput: slotStub( 'CdxTextInput' ),
	CdxIcon: slotStub( 'CdxIcon' ),
	CdxAccordion: slotStub( 'CdxAccordion' ),
	CdxLookup: slotStub( 'CdxLookup' )
};
