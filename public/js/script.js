function handleImageError(img) {
    const fallbackImageUrl = "/vendor/image-domain-replace/img/default_image.png";

    if (!img.errorCount) {
        img.errorCount = 0;
    }
    img.errorCount++;

    // Nếu lỗi quá 1 lần thì gán ảnh mặc định
    if (img.errorCount > 1) {
        setImageSrc(img, fallbackImageUrl);
        return;
    }

    const metaToken = document.querySelector('meta[name="csrf-token"]');
    if (!metaToken) {
        setImageSrc(img, fallbackImageUrl);
        return;
    }

    fetch("/ajax/get-fallback-image-url", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": metaToken.getAttribute("content"),
        },
        body: JSON.stringify({
            imageUrl: img.src,
        }),
    })
    .then((response) => {
        if (response.ok) {
            return response.json();
        } else {
            throw new Error("Failed to fetch fallback image URL");
        }
    })
    .then((data) => {
        if (data.fallbackImageUrl) {
            setImageSrc(img, data.fallbackImageUrl);
        } else {
            setImageSrc(img, fallbackImageUrl);
        }
    })
    .catch(() => {
        setImageSrc(img, fallbackImageUrl);
    });
}

function setImageSrc(img, url) {
    img.src = url;
    if (img.hasAttribute('data-src')) {
        img.setAttribute('data-src', url);
    }
    if (img.hasAttribute('data-original')) {
        img.setAttribute('data-original', url);
    }
}

function attachErrorHandler(img) {
    // Tránh gắn handler nhiều lần
    if (img.dataset.errorHandlerAttached) {
        return;
    }

    img.dataset.errorHandlerAttached = 'true';

    // Chỉ gán fallback khi thật sự lỗi
    img.onerror = function() {
        handleImageError(this);
    };
}

function onErrorImage() {
    // Xử lý tất cả ảnh hiện tại
    document.querySelectorAll("img").forEach(function (img) {
        attachErrorHandler(img);
    });

    // Theo dõi ảnh mới được thêm vào DOM (cho lazy load, Swiper, v.v.)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            mutation.addedNodes.forEach(function(node) {
                if (node.nodeName === 'IMG') {
                    attachErrorHandler(node);
                }
                if (node.querySelectorAll) {
                    node.querySelectorAll('img').forEach(function(img) {
                        attachErrorHandler(img);
                    });
                }
            });
        });
    });

    observer.observe(document.body, {
        childList: true,
        subtree: true
    });

    window.imageErrorObserver = observer;
}

window.onErrorImage = onErrorImage;

document.addEventListener("DOMContentLoaded", function () {
    onErrorImage();
});