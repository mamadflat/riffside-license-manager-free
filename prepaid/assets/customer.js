document.addEventListener(
	'click',
	async function (event) {
		const button = event.target.closest( '.yhlm-copy' );
		if ( ! button) {
			return;
		}
		const code = button.parentElement.querySelector( 'code' ).textContent;
		try {
			await navigator.clipboard.writeText( code );
			const label        = button.textContent;
			button.textContent = button.dataset.copied;
			setTimeout( () => { button.textContent = label; }, 2000 );
		} catch (_) {
			const range = document.createRange();
			range.selectNodeContents( button.parentElement.querySelector( 'code' ) );
			const selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
		}
	}
);
