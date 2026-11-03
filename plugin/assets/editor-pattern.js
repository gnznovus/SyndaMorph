/* global wp, SyndaMorphPattern */
( function () {
    'use strict';

    const createElement = wp.element.createElement;

    function SavePatternMenu() {
        const [ saving, setSaving ] = wp.element.useState( false );

        const save = async () => {
            const editor = wp.data.select( 'core/editor' );
            const content = editor.getEditedPostContent();
            const postId = editor.getCurrentPostId();
            const defaultName = editor.getEditedPostAttribute( 'title' ) || 'My Pattern';

            if ( ! content || ! content.trim() ) {
                window.alert( 'This page has no blocks to save as a pattern.' );
                return;
            }
            const name = await window.SyndaMorphPatternDialog.open( defaultName );
            if ( name === null ) { return; }

            setSaving( true );
            try {
                const pattern = await wp.apiFetch( {
                    url: SyndaMorphPattern.endpoint,
                    method: 'POST',
                    headers: { 'X-WP-Nonce': SyndaMorphPattern.nonce },
                    data: {
                        title: name.trim(),
                        content,
                        post_id: postId,
                    },
                } );
                wp.data.dispatch( 'core/notices' ).createSuccessNotice(
                    'Saved "' + pattern.title + '" to Patterns → SyndaMorph.',
                    { type: 'snackbar' }
                );
            } catch ( error ) {
                wp.data.dispatch( 'core/notices' ).createErrorNotice(
                    'Could not save pattern: ' + ( error.message || String( error ) ),
                    { type: 'snackbar' }
                );
            } finally {
                setSaving( false );
            }
        };

        return createElement(
            wp.editPost.PluginMoreMenuItem,
            { icon: 'layout', onClick: save, disabled: saving },
            saving ? 'Saving Pattern…' : 'Save as Pattern (SyndaMorph)'
        );
    }

    wp.plugins.registerPlugin( 'syndamorph-save-pattern', {
        render: SavePatternMenu,
    } );
} )();
