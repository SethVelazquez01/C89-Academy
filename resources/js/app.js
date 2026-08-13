const MAX_PRESENTATION_BYTES = 32 * 1024 * 1024;
const loadPresentationRenderer = () => import('@aiden0z/pptx-renderer');

window.openPrivatePresentation = async (container, url, signal, onSlideChange) => {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/vnd.openxmlformats-officedocument.presentationml.presentation' },
        signal,
    });

    if (!response.ok) {
        throw new Error('No fue posible cargar la presentación.');
    }

    const declaredSize = Number(response.headers.get('Content-Length') ?? 0);

    if (declaredSize > MAX_PRESENTATION_BYTES) {
        throw new Error('La presentación supera el tamaño permitido.');
    }

    const presentation = await response.arrayBuffer();

    if (presentation.byteLength > MAX_PRESENTATION_BYTES) {
        throw new Error('La presentación supera el tamaño permitido.');
    }

    const { PptxViewer, RECOMMENDED_ZIP_LIMITS } = await loadPresentationRenderer();

    return PptxViewer.open(presentation, container, {
        fitMode: 'contain',
        lazyMedia: true,
        lazySlides: true,
        listOptions: {
            windowed: true,
            showSlideLabels: true,
        },
        onSlideChange,
        pdfjs: false,
        scrollContainer: container,
        zipLimits: RECOMMENDED_ZIP_LIMITS,
    });
};
