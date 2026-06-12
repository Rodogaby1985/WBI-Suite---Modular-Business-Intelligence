document.addEventListener('DOMContentLoaded', function() {
    // Función de copia al portapapeles
    window.wpoaCopyToClipboard = function(elementId) {
        var copyText = document.getElementById(elementId);
        if (copyText) {
            var textArea = document.createElement("textarea");
            textArea.value = copyText.textContent || copyText.innerText;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand("copy");
            textArea.remove();
            alert("¡Copiado al portapapeles!");
        }
    };
});