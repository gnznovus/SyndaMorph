/* global window, document */
( function () {
    'use strict';

    let active = false;

    /**
     * Shared accessible name dialog for import preview and Gutenberg editor.
     * Resolves to a name or null; no browser prompt or extra framework required.
     */
    window.SyndaMorphPatternDialog = {
        open( defaultName ) {
            if ( active ) { return Promise.resolve( null ); }
            active = true;

            return new Promise( ( resolve ) => {
                const previousFocus = document.activeElement;
                const backdrop = document.createElement( 'div' );
                backdrop.className = 'syndamorph-name-backdrop';
                backdrop.innerHTML =
                    '<section class="syndamorph-name-dialog" role="dialog" aria-modal="true" aria-labelledby="syndamorph-name-title" aria-describedby="syndamorph-name-help">' +
                    '  <header class="syndamorph-name-header"><h2 id="syndamorph-name-title">Save as Pattern</h2>' +
                    '    <button type="button" class="syndamorph-name-x" aria-label="Close dialog">&times;</button></header>' +
                    '  <div class="syndamorph-name-body"><p id="syndamorph-name-help">Save this layout to <strong>Patterns → SyndaMorph</strong>. Each inserted copy is independently editable.</p>' +
                    '    <label for="syndamorph-name-input"><strong>Pattern name</strong></label>' +
                    '    <input id="syndamorph-name-input" type="text" maxlength="200" autocomplete="off" required>' +
                    '    <p class="syndamorph-name-error" role="alert" hidden>Enter a pattern name.</p></div>' +
                    '  <footer class="syndamorph-name-actions"><button class="button syndamorph-name-cancel" type="button">Cancel</button>' +
                    '    <button class="button button-primary syndamorph-name-save" type="button">Save Pattern</button></footer>' +
                    '</section>';

                document.body.appendChild( backdrop );
                const dialog = backdrop.querySelector( '.syndamorph-name-dialog' );
                const input = backdrop.querySelector( '#syndamorph-name-input' );
                const error = backdrop.querySelector( '.syndamorph-name-error' );
                const save = backdrop.querySelector( '.syndamorph-name-save' );
                const cancel = backdrop.querySelector( '.syndamorph-name-cancel' );
                const close = backdrop.querySelector( '.syndamorph-name-x' );
                input.value = defaultName || '';

                const done = ( value ) => {
                    document.removeEventListener( 'keydown', onKeyDown, true );
                    backdrop.remove();
                    active = false;
                    if ( previousFocus && previousFocus.isConnected ) { previousFocus.focus(); }
                    resolve( value );
                };
                const submit = () => {
                    const name = input.value.trim();
                    if ( ! name ) {
                        error.hidden = false;
                        input.focus();
                        return;
                    }
                    done( name );
                };
                const onKeyDown = ( event ) => {
                    if ( event.key === 'Escape' ) {
                        event.preventDefault();
                        event.stopPropagation();
                        done( null );
                        return;
                    }
                    if ( event.key === 'Enter' && event.target === input ) {
                        event.preventDefault();
                        submit();
                        return;
                    }
                    if ( event.key !== 'Tab' ) { return; }
                    const controls = [ close, input, cancel, save ];
                    const first = controls[ 0 ];
                    const last = controls[ controls.length - 1 ];
                    if ( event.shiftKey && document.activeElement === first ) {
                        event.preventDefault();
                        last.focus();
                    } else if ( ! event.shiftKey && document.activeElement === last ) {
                        event.preventDefault();
                        first.focus();
                    }
                };
                document.addEventListener( 'keydown', onKeyDown, true );
                save.addEventListener( 'click', submit );
                cancel.addEventListener( 'click', () => done( null ) );
                close.addEventListener( 'click', () => done( null ) );
                backdrop.addEventListener( 'click', ( event ) => {
                    if ( event.target === backdrop ) { done( null ); }
                } );
                input.addEventListener( 'input', () => { error.hidden = true; } );
                input.focus();
                input.select();
            } );
        },
    };
} )();
