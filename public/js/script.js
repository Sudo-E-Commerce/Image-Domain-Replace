function onErrorImage() {
    document.querySelectorAll("img").forEach(function (img) {
        img.onerror = function () {
            const fallbackImageUrl = "/vendor/core/core/base/img/placeholder.png";
            
            if (!img.hasOwnProperty("errorCount")) {
                img.errorCount = 0;
            }

            img.errorCount++;

            if (img.errorCount > 1) {
                // Giới hạn số lần thử thay thế ảnh
                // console.error('Image replacement failed too many times:', img.src)
                img.src = fallbackImageUrl;
                
                // Xử lý data-src giống src
                if (img.hasAttribute('data-src')) {
                    img.setAttribute('data-src', fallbackImageUrl);
                }
                return;
            }
            
            const metaToken = document.querySelector('meta[name="csrf-token"]');
            if (!metaToken) {
                img.src = fallbackImageUrl;
                
                // Xử lý data-src giống src
                if (img.hasAttribute('data-src')) {
                    img.setAttribute('data-src', fallbackImageUrl);
                }
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
                        img.src = data.fallbackImageUrl;
                        
                        // Xử lý data-src giống src
                        if (img.hasAttribute('data-src')) {
                            img.setAttribute('data-src', data.fallbackImageUrl);
                        }
                    } else {
                        img.src = fallbackImageUrl;
                        
                        // Xử lý data-src giống src
                        if (img.hasAttribute('data-src')) {
                            img.setAttribute('data-src', fallbackImageUrl);
                        }
                    }
                })
                .catch((error) => {
                    console.error(
                        "An error occurred during the fetch request",
                        error
                    );
                    img.src = fallbackImageUrl;
                    
                    // Xử lý data-src giống src
                    if (img.hasAttribute('data-src')) {
                        img.setAttribute('data-src', fallbackImageUrl);
                    }
                });
        };
    });
}

window.onErrorImage = onErrorImage;
document.addEventListener("DOMContentLoaded", function () {
    onErrorImage();
});