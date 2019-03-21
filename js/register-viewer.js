document.addEventListener("DOMContentLoaded", function() {
	if (OCA.Viewer) {
		OCA.Viewer.registerHandler({
			id: 'camerarawpreviews',
			mimesAliases: {
				'image/x-dcraw': 'image/jpeg'
			}
		})
	}
});
