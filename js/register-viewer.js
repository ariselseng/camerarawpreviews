if (typeof OCA !== 'undefined' && OCA.Viewer && typeof OCA.Viewer.registerHandler === 'function') {
	const RAWViewer = {
		name: 'RAWViewer',
		props: {
			filename: { type: String, default: null },
			previewPath: { type: String, default: null },
		},
		render(createElement) {
			if (!this.previewPath) {
				return createElement('div', 'Preview not available')
			}

			const isAbsolute = /^https?:\/\//i.test(this.previewPath)
			const isRootRelative = this.previewPath.charAt(0) === '/'
			const url = (isAbsolute || isRootRelative)
				? this.previewPath
				: (typeof OC !== 'undefined' ? OC.generateUrl(this.previewPath) : this.previewPath)

			return createElement('img', {
				attrs: {
					src: url,
					alt: this.filename || 'RAW preview',
					style: 'max-width: 100%; max-height: 100%; object-fit: contain;',
				},
				on: {
					load: () => {
						if (typeof this.doneLoading === 'function') {
							this.doneLoading()
						}
					},
					error: () => {
						if (typeof this.doneLoading === 'function') {
							this.doneLoading()
						}
					},
				},
			})
		},
	}

	OCA.Viewer.registerHandler({
		id: 'camerarawpreviews',
		group: 'media',
		mimes: ['image/x-dcraw'],
		component: RAWViewer,
	})
}
