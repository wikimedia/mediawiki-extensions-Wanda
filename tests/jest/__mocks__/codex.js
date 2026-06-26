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
	CdxButton: slotStub( 'CdxButton' ),
	CdxTextArea: slotStub( 'CdxTextArea' ),
	CdxProgressBar: slotStub( 'CdxProgressBar' ),
	CdxCheckbox: slotStub( 'CdxCheckbox' ),
	CdxDialog: slotStub( 'CdxDialog' ),
	CdxTextInput: slotStub( 'CdxTextInput' ),
	CdxIcon: slotStub( 'CdxIcon' ),
	CdxAccordion: slotStub( 'CdxAccordion' )
};
