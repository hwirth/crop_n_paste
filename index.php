<?php ob_start(); ?>
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/
//
//  CROP'N'PASTE - copy(l)eft 2020 - https://harald.ist.org/
//  Paste a screenshot via clipboard, crop, apply transparency, get a link in your clipboard to paste in a chat
//
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/
<?php $COPYLEFT = ob_get_clean();
	define( 'DEBUG', !false );
	define( 'IMAGE_PATH', '/paste/images/' );
	define( 'ALLOWED_CHARS', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ_-0123456789' );
	define( 'MAX_AGE', 3600*24*10 );

	define( 'SELECTION_STROKE_STYLE', '#80f' );
	define( 'SELECTION_FILL_STYLE', 'rgba(16,0,64, 0.85)' );

	function remove_old_posts () {
		$files = glob( '*.png' );

		foreach( $files as $file ) {
			$age = time() - filemtime( $file );
			if ($age > MAX_AGE) unlink( $file );
		}

	} // remove_old_posts


	function sanitize ($tainted_string) {
		$clean_string = '';

		for ($i = 0; $i < strlen($tainted_string); ++$i) {
			$character = $tainted_string[$i];
			if (strpos( ALLOWED_CHARS, $character ) !== false) {
				$clean_string .= $character;
			}
		}

		return $clean_string;

	} // sanitize


	function save_uploaded_file () {
		if ($_REQUEST['iam'] != 'nobot') die( 'ERROR: Password.' );

		$error = $_FILES['paste_image']['error'];
		if ($error == UPLOAD_ERR_OK) {
			$tmp_name = $_FILES['paste_image']['tmp_name'];
			$tainted_name = basename( $_FILES['paste_image']['name'] );
			$clean_name = sanitize( $tainted_name );
			$file_name = $clean_name . '.png';

			if ($tainted_name != $clean_name) die( 'No hax pls.' );

			move_uploaded_file( $tmp_name, $_SERVER['DOCUMENT_ROOT'] . IMAGE_PATH . $file_name );

			if (DEBUG) error_log(
				"Crop'n'Paste: {$file_name} ("
				. $_FILES['paste_image']['size']
				. ')'
			);

			die( $file_name );
		} else {
			die( 'ERROR: Upload failed.' );
		}

	} // save_uploaded_file

	remove_old_posts();
	if (isset($_FILES['paste_image'])) save_uploaded_file();

	$GET_password = (isset($_REQUEST['iam']) ? $_REQUEST['iam'] : '' );
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>Crop'n'Paste</title>
<meta name="description" content="Image paste service">
<meta name="keywords"    content="paste,image">
<meta name="author"      content="Harald Markus Wirth, http://harald.ist.org/">
<meta name="robots"      content="index,nofollow">
<meta name="viewport"    content="width=device-width, initial-scale=1">
<link rel="shortcut icon" href="/favicon.ico" id="favicon">
<script>
<?= $COPYLEFT ?>

const IMAGE_PATH             = '<?= IMAGE_PATH ?>';
const ALLOWED_CHARS          = '<?= ALLOWED_CHARS ?>';
const DRAW_SELECTION_OUTLINE = true;
const FAST_FLOOD_FILL        = true;

const DOM = {
	formControls  : 'form',
	divPaste      : '#paste',
	inputPassword : '#password',
	buttonCrop    : '#button_crop',
	buttonAlpha   : '#button_alpha',
	inputFilename : '#file_name',
	buttonPost    : '#button_post',
	buttonCopy    : '#button_copy',
	buttonList    : '#button_list',
	pResult       : '#result',
	canvasPost    : '#canvas_post',
	canvasUi      : '#canvas_ui',
};

var selection = null;


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/
// DOM AND GENERAL HELPERS
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/

/**
 * gather_dom_elements()
 */
function gather_dom_elements () {
	Object.keys( DOM ).forEach( (key)=>{
		const selector = DOM[key];
		const element = document.querySelector( selector );
		DOM[key] = element;
	});

} // gather_dom_elements


/**
 * remove_noscript()
 */
function remove_noscript () {
	document.querySelectorAll( '.noscript' ).forEach( (element)=>{
		element.parentNode.removeChild( element );
	});

	document.body.classList.add( 'active' );

} // remove_noscript


/**
 * sanitize()
 */
function sanitize (tainted_string) {
	let clean_string = '';

	for (let i = 0; i < tainted_string.length; ++i) {
		const character = tainted_string.charAt( i );
		if (ALLOWED_CHARS.indexOf( character ) >= 0) {
			clean_string += character;
		}
	}

	return clean_string;

} // sanitize


/**
 * formatted_date()
 */
function formatted_date (date = null) {
	if (date === null) date = new Date();

	const year    = date.getFullYear();
	const month   = date.getMonth() + 1;
	const day     = date.getDate();
	const hours   = date.getHours();
	const minutes = date.getMinutes();
	const seconds = date.getSeconds();

	return (
		year
		+ '-' + ((month   < 10) ? '0' : '') + month
		+ '-' + ((day     < 10) ? '0' : '') + day
		+ '_' + ((hours   < 10) ? '0' : '') + hours
		+ '-' + ((minutes < 10) ? '0' : '') + minutes
		+ '-' + ((seconds < 10) ? '0' : '') + seconds
	);

} // formatted_date


/**
 * copy_to_clipboard()
 * https://stackoverflow.com/questions/400212/how-do-i-copy-to-the-clipboard-in-javascript
 */
function copy_to_clipboard (text) {
	console.log( 'Copying to clipboard:', text );

	if (window.clipboardData && window.clipboardData.setData) {
		// Internet Explorer-specific code path to prevent textarea being shown while dialog is visible.
		return clipboardData.setData( "Text", text );

	}
	else if (document.queryCommandSupported && document.queryCommandSupported( 'copy' )) {
		var textarea = document.createElement( 'textarea' );
		textarea.textContent = text;
		textarea.style.position = 'fixed';  // Prevent scrolling to bottom of page in Microsoft Edge.
		document.body.appendChild( textarea );
		textarea.select();
		try {
			return document.execCommand( 'copy' );  // Security exception may be thrown by some browsers.
		}
		catch (ex) {
			console.warn( 'Copy to clipboard failed.', ex );
			return false;
		}
		finally {
			document.body.removeChild( textarea );
		}
	}

} // copy_to_clipboard


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/
// FLOOD FILL
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/

/**
 * get_pixel()
 */
function get_pixel (image_data, x, y) {
	if (x < 0 || y < 0 || x >= image_data.width || y >= image_data.height) {
		return [-1, -1, -1, -1];
	} else {
		const offset = (y * image_data.width + x) * 4;
		return image_data.data.slice(offset, offset + 4);
	}

} // get_pixel


/**
 * set_pixel()
 */
function set_pixel (image_data, x, y, color) {
	const offset = (y * image_data.width + x) * 4;
	image_data.data[offset + 0] = color[0];
	image_data.data[offset + 1] = color[1];
	image_data.data[offset + 2] = color[2];
	image_data.data[offset + 3] = color[3];

} // set_pixel


/**
 * colors_match()
 */
function colors_match (a, b, range_squared = null) {
	if (range_squared === null) {
		return (
			a[0] === b[0]
			&& a[1] === b[1]
			&& a[2] === b[2]
			&& a[3] === b[3]
		);
	} else {
		const dr = a[0] - b[0];
		const dg = a[1] - b[1];
		const db = a[2] - b[2];
		const da = a[3] - b[3];
		return dr * dr + dg * dg + db * db + da * da < range_squared;
	}

} // colors_match


/**
 * flood_fill_naive()
 */
function flood_fill_naive (context, x, y, fill_color, range = 1) {
	const image_data = context.getImageData( 0, 0, context.canvas.width, context.canvas.height );
	const visited = new Uint8Array( image_data.width, image_data.height );
	const target_color = get_pixel( image_data, x, y );

	if (colors_match( target_color, [0,0,0,0] )) return;

	if (! colors_match( target_color, fill_color )) {
		const range_squared = range * range;
		const pixels_to_check = [x, y];

		while (pixels_to_check.length > 0) {
			const y = pixels_to_check.pop();
			const x = pixels_to_check.pop();
			const currentColor = get_pixel(image_data, x, y);

			if( !visited[y * image_data.width + x]
			&&  colors_match( currentColor, target_color, range_squared )
			) {
				set_pixel( image_data, x, y, fill_color );
				visited[y * image_data.width + x] = 1;	// mark we were here already
				pixels_to_check.push( x + 1, y );
				pixels_to_check.push( x - 1, y );
				pixels_to_check.push( x, y + 1 );
				pixels_to_check.push( x, y - 1 );
			}
		}

		context.putImageData( image_data, 0, 0 );
	}

} // flood_fill_naive


/**
 * flood_fill_optimized()
 */
function flood_fill_optimized (context, x, y, fill_color) {

	function get_pixel (pixel_data, x, y) {
		if (x < 0 || y < 0 || x >= pixel_data.width || y >= pixel_data.height) {
			return -1;  // impossible color
		} else {
			return pixel_data.data[y * pixel_data.width + x];
		}
	}

	const image_data = context.getImageData( 0, 0, context.canvas.width, context.canvas.height );
	const pixel_data = {
		width  : image_data.width,
		height : image_data.height,
		data   : new Uint32Array( image_data.data.buffer ),
	};

	const target_color = get_pixel( pixel_data, x, y );

	if (target_color === 0) return;

	if (target_color !== fill_color) {
		const pixels_to_check = [x, y];

		while (pixels_to_check.length > 0) {
			const y = pixels_to_check.pop();
			const x = pixels_to_check.pop();
			const current_color = get_pixel( pixel_data, x, y );

			if (current_color === target_color) {
				pixel_data.data[y * pixel_data.width + x] = fill_color;
				pixels_to_check.push( x + 1, y );
				pixels_to_check.push( x - 1, y );
				pixels_to_check.push( x, y + 1 );
				pixels_to_check.push( x, y - 1 );
			}
		}

		context.putImageData( image_data, 0, 0 );
	}

} // flood_fill_optimized


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/
// CANVAS HELPERS
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/

/**
 * size_canvas()
 */
function size_canvas (canvas, new_width, new_height) {
	canvas.setAttribute( 'width', canvas.width = new_width );
	canvas.setAttribute( 'height', canvas.height = new_height );

	const context = canvas.getContext( '2d' );
	context.clearRect( 0, 0, new_width, new_height );

} // size_canvas


/**
 * extend_post_canvas()
 */
function extend_post_canvas () {
	const width = DOM.canvasPost.width;
	const height = DOM.canvasPost.height;
	const image_data = DOM.contextPost.getImageData( 0, 0, width, height );

	DOM.canvasPost.width += 2;
	DOM.canvasPost.height += 2;

	const r = image_data.data[0];
	const g = image_data.data[1];
	const b = image_data.data[2];
	DOM.contextPost.fillStyle = 'rgb(' + r + ',' + g + ',' + b + ')';
	DOM.contextPost.fillRect( 0, 0, width+2, height+2 );
	DOM.contextPost.putImageData( image_data, 1, 1 );

} // extend_post_canvas


/**
 * reduce_post_canvas()
 */
function reduce_post_canvas () {

	function row_transparent (y) {
		for (let x = 0; x < width; ++x) {
			const pixel = get_pixel( image_data, x, y );
			if (!colors_match( pixel, [0,0,0,0] )) return false;
		}

		return true;
	}


	function column_transparent (x) {
		for (let y = 0; y < height; ++y) {
			const pixel = get_pixel( image_data, x, y );
			if (!colors_match( pixel, [0,0,0,0] )) return false;
		}

		return true;
	}


	const width = DOM.canvasPost.width;
	const height = DOM.canvasPost.height;

	const image_data = DOM.contextPost.getImageData( 0, 0, width, height );

	let left = 0;
	let top = 0;
	let right = width - 1;
	let bottom = height - 1;

	while ((top    < height) && row_transparent   (top)   ) ++top;
	while ((bottom > 0     ) && row_transparent   (bottom)) --bottom;
	while ((left   < width ) && column_transparent(left)  ) ++left;
	while ((right  > 0     ) && column_transparent(right) ) --right;

	if ((left > right) || (top > bottom)) return;

	const new_width = right - left + 1;
	const new_height = bottom - top + 1;

	const cropped_data = DOM.contextPost.getImageData(
		left,
		top,
		new_width,
		new_height,
	);

	size_canvas( DOM.canvasPost, new_width, new_height );
	DOM.contextPost.putImageData( cropped_data, 0, 0 );

	clear_selection();

} // reduce_post_canvas


/**
 * reposition_post_canvas()
 */
function reposition_post_canvas () {
	const paste_rect = DOM.divPaste.getBoundingClientRect();
	const post_rect = DOM.canvasPost.getBoundingClientRect();

	const post_width_2 = Math.floor( post_rect.width / 2 );
	const post_height_2 = Math.floor( post_rect.height / 2 );

	const mid_x = Math.floor( paste_rect.width / 2 );
	const mid_y = Math.floor( paste_rect.height / 2 );

	const left = Math.max( 0, mid_x - post_width_2 );
	const top = Math.max( 0, mid_y - post_height_2 );

	DOM.canvasPost.style.left = left + 'px';
	DOM.canvasPost.style.top = top + 'px';

} // reposition_post_canvas


/**
 * resize_ui_canvas()
 */
function resize_ui_canvas () {
	const rect_paste = DOM.divPaste.getBoundingClientRect();
	const rect_post = DOM.canvasPost.getBoundingClientRect();

	const new_width = Math.max( rect_paste.width, rect_post.width );
	const new_height = Math.max( rect_paste.height, rect_post.height );

	size_canvas( DOM.canvasUi, new_width, new_height );

} // resize_ui_canvas


/**
 * clear_selection()
 */
function clear_selection (new_selection = null) {
	selection = new_selection;
	DOM.contextUi.clearRect( 0, 0, DOM.canvasUi.width, DOM.canvasUi.height );

} // clear_selection


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/
// EVENT HANDLERS
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/

/**
 * on_dragstart()
 */
function on_dragstart (event) {
	const items = event.dataTransfer.items;

	if (items && (items.length == 1)) {
		event.dataTransfer.effectAllowed = 'copy';
		event.dataTransfer.dropEffect = 'copy';
	} else {
		event.dataTransfer.effectAllowed = 'none';
		event.dataTransfer.dropEffect = 'none';
	}

	console.log( 'on_dragstart: dropEffect:', event.dataTransfer.dropEffect, 'effectAllowed:', event.dataTransfer.effectAllowed );
	event.preventDefault();

} // on_dragstart


/**
 * on_dragenter()
 */
function on_dragenter (event) {
	const items = event.dataTransfer.items;

	if (items && (items.length == 1)) {
		event.dataTransfer.effectAllowed = 'copy';
		event.dataTransfer.dropEffect = 'copy';
	} else {
		event.dataTransfer.effectAllowed = 'none';
		event.dataTransfer.dropEffect = 'none';
	}

	console.log( 'on_dragenter: dropEffect:', event.dataTransfer.dropEffect, 'effectAllowed:', event.dataTransfer.effectAllowed );
	event.preventDefault();

} // on_dragenter


/**
 * on_dragover()
 */
function on_dragover (event) {
	const items = event.dataTransfer.items;
/*
	if (items && (items.length == 1)) {
		event.dataTransfer.effectAllowed = 'copy';
		event.dataTransfer.dropEffect = 'copy';
		console.log( 'Allow' );
	} else {
		event.dataTransfer.effectAllowed = 'none';
		event.dataTransfer.dropEffect = 'none';
		console.log( 'Deny' );
	}
*/
	console.log( 'on_dragover: dropEffect:', event.dataTransfer.dropEffect, 'effectAllowed:', event.dataTransfer.effectAllowed );
	event.preventDefault();

} // on_dragover


/**
 * on_new_image()
 */
function on_new_image (new_img) {
	size_canvas( DOM.canvasPost, new_img.naturalWidth, new_img.naturalHeight );
	DOM.contextPost.drawImage( new_img, 0, 0 );
	reposition_post_canvas();
	resize_ui_canvas();

} // on_new_image


/**
 * on_drop()
 */
function on_drop (event) {
	console.log( 'on_drop' );
	const items = event.dataTransfer.items;

	if (items && (items.length == 1)) {
		const dropped_image = new Image();

		dropped_image.onload = function () {
			on_new_image( dropped_image );
		};

		const file = items[0].getAsFile();
		dropped_image.src = URL.createObjectURL( file );
	} else {
		console.log( 'Denied' );
		alert( 'Cannot paste more than one image.' );
	}

	event.preventDefault();

} // on_drop


/**
 * on_paste()
 */
function on_paste (event) {
	var items = event.clipboardData.items;
	var blob = items[0].getAsFile();
	var reader = new FileReader();

	reader.onload = function (event) {
		var pasted_image = new Image;

		pasted_image.onload = function () {
			on_new_image( pasted_image );
		}

		pasted_image.src = event.target.result;

		DOM.pResult.classList.add( 'hidden' );
		reposition_post_canvas();
		resize_ui_canvas();
		clear_selection();

	}; // data url

	reader.readAsDataURL( blob );

} // on_paste






/**
 * on_input_filename_key_up()
 */
function on_input_filename_key_up () {
	const input_element = DOM.inputFilename;
	const tainted = input_element.value;
	const clean = sanitize( tainted );

	if (tainted != clean) {
		input_element.value = clean;
		alert( 'Allowed characters:\n' + ALLOWED_CHARS );
	}

} // on_input_filename_key_up


/**
 * on_canvas_ui_mousedown()
 */
function on_canvas_ui_mousedown (event) {
	if (event.button != 0) return;

	const start_x = event.layerX;
	const start_y = event.layerY;
	const canvas  = DOM.canvasUi;
	const context = DOM.contextUi;
	const rect    = DOM.canvasPost.getBoundingClientRect();

	function on_mouse_move (event) {
		const delta_x = Math.floor( event.layerX - start_x );
		const delta_y = Math.floor( event.layerY - start_y );

		const left = Math.floor( Math.min(start_x, event.layerX) );
		const top = Math.floor( Math.min(start_y, event.layerY) );
		const width = Math.abs( delta_x );
		const height = Math.abs( delta_y );
		const bottom = top + height;
		const right = left + width;

		clear_selection({
			left   : left - DOM.canvasPost.offsetLeft,
			top    : top - DOM.canvasPost.offsetTop,
			width  : width,
			height : height,
		});

		context.fillStyle = '<?= SELECTION_FILL_STYLE ?>';
		context.fillRect( 0, 0, canvas.width, top );
		context.fillRect( 0, bottom, canvas.width, canvas.height - bottom );
		context.fillRect( 0, top, left, height );
		context.fillRect( right, top, canvas.width - right, height );

		if (DRAW_SELECTION_OUTLINE) {
			context.strokeStyle = '<?= SELECTION_STROKE_STYLE ?>';
			context.beginPath();
			context.rect( left-0.5, top-0.5, width+1.5, height+1.5 );
			context.stroke();
		}

	} // on_mouse_move

	function on_mouse_up (event) {
		canvas.removeEventListener( 'mousemove', on_mouse_move );
		canvas.removeEventListener( 'mouseup', on_mouse_up );

		if (selection === null) context.clearRect( 0, 0, canvas.width, canvas.height );

	} // on_mouse_up


	canvas.addEventListener( 'mousemove', on_mouse_move );
	canvas.addEventListener( 'mouseup', on_mouse_up );

	DOM.pResult.classList.add( 'hidden' );

	reposition_post_canvas();
	resize_ui_canvas();
	clear_selection();

} // on_canvas_ui_mousedown


/**
 * on_button_alpha_mouseup()
 */
function on_button_alpha_mouseup (event) {
	if (event.button != 0) return;

	extend_post_canvas();

	document.body.classList.add( 'busy' );
	setTimeout( ()=>{
		const x = 0;
		const y = 0;

		if (FAST_FLOOD_FILL) {
			const color = 0;
			flood_fill_optimized( DOM.contextPost, x, y, color );
		} else {
			const color = [0,0,0,0];
			const range = 3;
			flood_fill_naive( DOM.contextPost, x, y, color, range );
		}

		reduce_post_canvas();

		reposition_post_canvas();
		resize_ui_canvas();
		clear_selection();

		document.body.classList.remove( 'busy' );
	});

} // on_button_alpha_mouseup


/**
 * on_button_crop_mouseup()
 */
function on_button_crop_mouseup (event) {
	if (event.button != 0) return;

	const image_data = DOM.contextPost.getImageData(
		selection.left,
		selection.top,
		selection.width,
		selection.height,
	);

	size_canvas( DOM.canvasPost, selection.width, selection.height );
	DOM.contextPost.putImageData( image_data, 0, 0 );

	reposition_post_canvas();
	resize_ui_canvas();
	clear_selection();

} // on_button_crop_mouseup


/**
 * on_button_post_mouseup()
 */
function on_button_post_mouseup (event) {
	if (event.button != 0) return;

	const canvas = DOM.canvasPost;
	const file_name = DOM.inputFilename.value;
	const password = DOM.inputPassword.value;

	if (password == '') {
		DOM.inputPassword.select();
		return;
	}

	clear_selection();

	canvas.toBlob( (blob)=>{
		if (blob === null) return;

		const data = new FormData();
		data.append( 'paste_image', blob, file_name );
		data.append( 'iam', password );

		var xhr
		= (window.XMLHttpRequest)
		? new XMLHttpRequest()
		: new activeXObject("Microsoft.XMLHTTP")
		;

		xhr.onreadystatechange = function () {
			if (xhr.readyState === 4) {
				let success = false;

				if (xhr.response.substr(0, 5) == 'ERROR') {
					DOM.pResult.innerHTML = xhr.response;

				} else {
					const url = 'https://' + location.hostname + IMAGE_PATH + xhr.response;
					DOM.pResult.innerHTML = '<a href="' + url + '">' + url + '</a>';
					DOM.inputFilename.value = formatted_date();

					success = copy_to_clipboard( url );
				}

				DOM.pResult.classList.toggle( 'success', success );
				DOM.pResult.classList.remove( 'hidden' );

				reposition_post_canvas();
				resize_ui_canvas();
			}
		}

		xhr.open( 'POST', 'index.php', true );
		xhr.send( data );

	}, 'image/png');

} // on_button_post_mouseup


/**
 * on_button_list_mouseup(event)
 */
function on_button_list_mouseup () {
	if (event.button != 0) return;

	location.href = '<?= IMAGE_PATH ?>?C=M;O=A';

} // on_button_list_mouseup


/**
 * on_body_resize()
 */
function on_body_resize () {
	reposition_post_canvas();
	resize_ui_canvas();

} // on_body_resize


/**
 * on_body_mouseup()
 */
function on_body_mouseup (event) {
	if (event.button != 0) return;

	const id = event.target.id;

	if ((id == 'paste') || (id == 'controls')) {
		clear_selection();
	}

} // on_body_mouseup


/**
 * on_form_submit()
 */
function on_form_submit (event) {
	event.preventDefault();
	return false;

} // on_form_submit


//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/
// INIT
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////119:/

/**
 * body.onload()
 */
addEventListener( 'load', ()=>{
	gather_dom_elements();

	DOM.contextPost   = DOM.canvasPost.getContext( '2d' );
	DOM.contextUi     = DOM.canvasUi.getContext( '2d' );

	size_canvas( DOM.canvasPost, 0, 0 );

	DOM.formControls .addEventListener( 'submit',    on_form_submit );
	DOM.inputFilename.addEventListener( 'keyup',     on_input_filename_key_up );
	DOM.buttonCrop   .addEventListener( 'mouseup',   on_button_crop_mouseup );
	DOM.buttonAlpha  .addEventListener( 'mouseup',   on_button_alpha_mouseup );
	DOM.buttonPost   .addEventListener( 'mouseup',   on_button_post_mouseup );
	DOM.buttonList   .addEventListener( 'mouseup',   on_button_list_mouseup );
	DOM.canvasUi     .addEventListener( 'mousedown', on_canvas_ui_mousedown );

	remove_noscript();
	on_body_resize();

	DOM.inputFilename.value = formatted_date();
	//DOM.inputFilename.select();

	function close_help () {
		removeEventListener( 'keydown', close_help );
		removeEventListener( 'mousedown', close_help );

		const help = document.querySelector( '#help' );
		help.parentNode.removeChild( help )
	}
	addEventListener( 'keydown', close_help );
	addEventListener( 'mousedown', close_help );

	addEventListener( 'resize',  on_body_resize );
	addEventListener( 'paste',   on_paste );
	addEventListener( 'mouseup', on_body_mouseup );

	addEventListener( 'dragstart', on_dragstart );
	addEventListener( 'dragenter', on_dragenter );
	addEventListener( 'dragover',  on_dragover );
	addEventListener( 'drop',      on_drop );

}); // body.onload()

</script><style>
* { margin:0; padding:0; box-sizing:border-box; line-height:1.4; }
html, body { width:100%; height:100%; font-family:sans-serif; Xbackground:#fff; color:#000; text-align:center; }
div.noscript { z-index:800; display:table; position:absolute; top:0; left:0; width:100%; height:100%; }
.noscript div { display:table-cell; text-align:center; vertical-align:middle; background:#123; color:#fff; }
.noscript h1 { font-size:2em; margin:0 0 0.1em; color:#135; text-shadow:1px 0 #fa0,-1px 0 #fa0,0 -1px #fa0,0 1px #fa0; }
.noscript a { color:#4ac; }
.noscript .error { display:inline-block; text-align:left; font-family:monospace; }
.hidden { display:none; }
body.busy { filter:brightness(0.25); }
body.active {
	display:grid;
	grid-template-rows:min-content 1fr;
	grid-template-areas:"controls" "paste";

	background:url("data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAYAAAAf8/9hAAAABHNCSVQICAgIfAhkiAAAADBJ\
	REFUOE9jTEtL+8+ABxgbG+OTZmAcNWBYhMHMmTPxpoOzZ8/iTwejBjAwDv0wAADSgj/p4jinSwAAAABJRU5ErkJggg==");

}
#controls { grid-area:controls; background:<?= SELECTION_FILL_STYLE ?>; }
#paste { grid-area:paste; position:relative; overflow:auto; }
form { margin:0.25em 0 0.5em; }
input, button { text-align:center; border:solid 2px #000; border-radius:4px; background:#fff; color:#000; }
input:focus, button:focus { border-color:#8af; }
button { padding:0 0.5em; }
#password { width:5em; }
#password:invalid { border-color:#f00; background:#ff0; }
#file_name { width:13em; }
#result { margin:0 0 0.75em; padding:0.1em; background:#a20; color:#ff0; }
#result a { color:#ff0; text-decoration:none; }
#result a:hover { text-decoration:underline; }
#result.success { background:#362; color:#0f0; }
#result.success a { color:#0f0; }
#canvas_post { position:relative; display:block; box-shadow:0 0 0 9999px <?= SELECTION_FILL_STYLE ?>; text-align:left; }
#canvas_ui { position:absolute; top:0; left:0; }
#help { position:absolute; top:0; left:0; padding:0.25em 0.5em 0.5em; background:#fff; color:#000; text-align:left; }
h1 { font-size:1.2em; margin:0 0 0.25em; }
ol { display:inline-block; padding:0 0 0 1.25em; }
hr, p { margin:1em 0 0; }
</style></head><body>

<div class="noscript">
	<div>
		<h1>Crop'n'Paste</h1>
		<p>Please enable JavaScript!</p>
	</div>
</div>
<script class="noscript">document.querySelector("div.noscript p").innerText = "Initializing...";</script>

<div id="controls">
	<form action="javascript:false">
		<input type="text" class="hidden" name="username" value="crop'n'paste">
		<input type="password" id="password" placeholder="nobot" value="<?= $GET_password ?>" required
			title="Enter the password"
		>
		<button id="button_crop" title="Reduze image size to the selected area">Crop</button>
		<button id="button_alpha" title="Set pixels on the outside transparent and automatically crop the image">Alpha</button>
		<input type="text" id="file_name" value="paste" title="Set the desired filename of the posted image">
		<button id="button_post" title="Upload the current image">Post</button>
		<button id="button_list" title="Show all uploaded images">List</button>
	</form>
	<p id="result" class="hidden"></p>
</div>
<div id="paste">
	<canvas id="canvas_post"></canvas>
	<canvas id="canvas_ui"></canvas>
</div>

<div id="help">
	<h1>Quickly share a screen shot</h1>
	<ol>
		<li>[Alt] + [Print Screen]
		<li>Focus this page, press [Ctrl] + [v]
		<li>Enter <q>nobot</q> to the password field
		<li>Click <q>Post</q> (Link goes to your clipboard)
		<li>Focus the chat, press [Ctrl] + [v]
	</ol>
	<hr>
	<p>
		Alternatively, you can drag an image file
		<br>
		from your file mananger in here.
	</p>
</div>

</html>