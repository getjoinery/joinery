/**
 * JoineryDriveUpload - putting one file into Drive from a page: the one copy of
 * Drive's upload transport in browser code.
 *
 *   JoineryDriveUpload.send(blob, init, completeExtra, onProgress)
 *     init           the drive_upload_init body: name, size_bytes, mime_type,
 *                    and optionally sha256, folder_id, file_id
 *     completeExtra  fields drive_upload_complete takes beside the token (an
 *                    encrypted upload's metadata and wrapped keys), or null
 *     onProgress     optional fn(fraction 0..1) as chunks land
 *   resolves {deduped, file, warning}: file is the listing entry Drive answers
 *   with (drive_upload_complete, or drive_upload_init when it already held
 *   these bytes), warning any note the completion carried. A refusal rejects
 *   with the API's error, whose .data carries the reason (name_taken with its
 *   holder, file_too_large, quota, no_room, drive_disabled).
 *
 *   JoineryDriveUpload.sha256Hex(blob)  the hex SHA-256 Drive dedups on, or
 *   null where the browser cannot hash.
 *
 * The protocol: drive_upload_init, then PUT /api/v1/drive_upload/{token} in
 * chunks of the size init names (a 409 answers with the bytes the server
 * already holds, and the loop resumes from there), then drive_upload_complete.
 * Used by the Drive page (assets/js/drive.js) for plain and encrypted uploads,
 * and by the mail reader to save an attachment's copy.
 *
 * @version 1.1.0 - a resume (409) that does not move the offset forward is given up after a few tries
 *   instead of looping (reviewer2 B10)
 * @version 1.0.0
 */
(function () {
	'use strict';

	function api() { return window.joineryApi; }

	async function sha256Hex(blob) {
		if (!(window.crypto && crypto.subtle)) return null;
		try {
			var buf = await blob.arrayBuffer();
			var digest = await crypto.subtle.digest('SHA-256', buf);
			return Array.prototype.map.call(new Uint8Array(digest), function (b) {
				return b.toString(16).padStart(2, '0');
			}).join('');
		} catch (e) { return null; }
	}

	function putChunk(token, blob, start, end, total) {
		return fetch('/api/v1/drive_upload/' + encodeURIComponent(token), {
			method: 'PUT',
			credentials: 'same-origin',
			headers: {
				'X-Joinery-Csrf': api().csrf(),
				'Content-Range': 'bytes ' + start + '-' + end + '/' + total,
				'Content-Type': 'application/octet-stream'
			},
			body: blob
		});
	}

	async function send(blob, init, completeExtra, onProgress) {
		var started = await api().post('drive_upload_init', init);
		if (started.deduped) {
			if (onProgress) onProgress(1);
			return { deduped: true, file: started.file || null, warning: null };
		}
		var token = started.upload_token;
		var chunkBytes = started.chunk_bytes || 8388608;
		var total = blob.size;
		var offset = 0;
		var stalled = 0;   // resumes in a row that did not move forward
		while (offset < total) {
			var end = Math.min(offset + chunkBytes, total);
			var resp = await putChunk(token, blob.slice(offset, end), offset, end - 1, total);
			if (resp.status === 409) {
				var j = await resp.json();
				var resumeAt = (j.data && j.data.received_bytes) || 0;
				if (resumeAt <= offset && ++stalled >= 3) {
					throw new Error('The upload stopped moving forward. Try again.');
				}
				if (resumeAt > offset) stalled = 0;
				offset = resumeAt;
				continue;
			}
			stalled = 0;
			if (!resp.ok) {
				var msg = 'chunk failed';
				try { var bad = await resp.json(); if (bad && bad.error) msg = bad.error; } catch (e) { /* the status is all there is */ }
				throw new Error(msg);
			}
			var ok = await resp.json();
			offset = ok.data.received_bytes;
			if (onProgress) onProgress(total ? offset / total : 1);
		}
		var done = await api().post('drive_upload_complete', Object.assign({ upload_token: token }, completeExtra || {}));
		if (onProgress) onProgress(1);
		return { deduped: false, file: done.file || null, warning: done.warning || null };
	}

	window.JoineryDriveUpload = { send: send, sha256Hex: sha256Hex };
})();
