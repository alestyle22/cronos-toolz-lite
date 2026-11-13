/* assets/tools/renombrador.js — código de renombrador.html (sin código en línea: CSP estricta) */
        const imageUpload = document.getElementById('image-upload');
        const previewContainer = document.getElementById('image-preview');
        const resultList = document.getElementById('result-list');
        const groupNameInput = document.getElementById('group-name-input');
        const zipDownloadButton = document.getElementById('zip-download-btn');
        const uploadedFiles = []; // Almacena info y el objeto File original

        imageUpload.addEventListener('change', handleFileSelect);

        function handleFileSelect(event) {
            // Limpiar todo y resetear
            previewContainer.innerHTML = '';
            resultList.innerHTML = '<li>Carga imágenes y presiona "Descargar en ZIP".</li>';
            uploadedFiles.length = 0;
            groupNameInput.value = '';
            zipDownloadButton.disabled = true;

            const files = event.target.files;
            if (files.length === 0) {
                previewContainer.innerHTML = '<p id="no-files-message">Aún no hay imágenes cargadas.</p>';
                return;
            }
            
            const noFilesMessage = document.getElementById('no-files-message');
            if (noFilesMessage) noFilesMessage.remove();
            
            // Habilitar el botón de descarga tan pronto se cargan los archivos
            zipDownloadButton.disabled = false;

            Array.from(files).forEach((file, index) => {
                if (file.type.startsWith('image/')) {
                    const fileInfo = {
                        file: file,
                        nameInputId: `name-input-${index}`,
                        dimensions: { width: 0, height: 0 }
                    };
                    uploadedFiles.push(fileInfo);

                    const reader = new FileReader();
                    reader.onload = (e) => {
                        const img = new Image();
                        img.onload = () => {
                            // Guardar las dimensiones
                            fileInfo.dimensions.width = img.width;
                            fileInfo.dimensions.height = img.height;

                            const previewItem = document.createElement('div');
                            previewItem.className = 'preview-item';
                            const defaultName = file.name.substring(0, file.name.lastIndexOf('.')) || file.name;

                            previewItem.innerHTML = `
                                <img src="${e.target.result}" alt="${file.name}">
                                <span>${img.width}x${img.height}</span>
                                <input type="text" id="${fileInfo.nameInputId}" value="${defaultName}">
                            `;
                            previewContainer.appendChild(previewItem);
                        };
                        img.src = e.target.result;
                    };
                    reader.readAsDataURL(file);
                }
            });
        }
        
        function applyGroupName() {
            const groupName = groupNameInput.value.trim();
            if (groupName === '') {
                alert('Por favor, ingresa un nombre base grupal.');
                return;
            }
            if (uploadedFiles.length === 0) {
                 alert('Por favor, primero carga imágenes.');
                return;
            }

            uploadedFiles.forEach(fileInfo => {
                const nameInput = document.getElementById(fileInfo.nameInputId);
                if (nameInput) {
                    const suffix = uploadedFiles.length > 1 ? `_${uploadedFiles.indexOf(fileInfo) + 1}` : '';
                    nameInput.value = groupName + suffix;
                }
            });
        }

        /**
         * Crea un archivo ZIP con todas las imágenes renombradas.
         */
        async function downloadImagesZip() {
            if (uploadedFiles.length === 0) {
                alert('Por favor, carga imágenes primero.');
                return;
            }
            
            zipDownloadButton.disabled = true;
            resultList.innerHTML = '<li class="loading-message">Procesando... Creando archivo ZIP (puede tardar un momento).</li>';

            const zip = new JSZip();
            let allValid = true;

            for (const fileInfo of uploadedFiles) {
                const nameInput = document.getElementById(fileInfo.nameInputId);
                const baseName = nameInput ? nameInput.value.trim() : 'imagen'; 

                if (!baseName) {
                    alert(`El campo de nombre para la imagen ${fileInfo.file.name} está vacío.`);
                    allValid = false;
                    break;
                }

                const file = fileInfo.file;
                const extension = file.name.substring(file.name.lastIndexOf('.'));
                
                // Generar el nombre final
                const finalName = `${baseName}_${fileInfo.dimensions.width}x${fileInfo.dimensions.height}${extension}`;
                
                // Añadir el archivo al ZIP
                // Utilizamos file.slice() para obtener los datos binarios del archivo
                zip.file(finalName, file);
                
                // Actualizar la lista (opcional, para feedback)
                resultList.innerHTML += `<li>Añadiendo: ${finalName}</li>`;
            }
            
            if (!allValid) {
                zipDownloadButton.disabled = false;
                resultList.innerHTML = '<li>⚠️ Operación cancelada debido a un nombre faltante.</li>';
                return;
            }

            // Generar el contenido del ZIP
            zip.generateAsync({type:"blob"})
            .then(function(content) {
                // Usar la misma técnica de descarga con el Blob del ZIP
                const now = new Date();
                const filename = `imagenes_renombradas_${now.getFullYear()}${now.getMonth()+1}${now.getDate()}.zip`;
                
                const url = URL.createObjectURL(content);
                const a = document.createElement('a');
                a.href = url;
                a.download = filename;
                
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(url);
                
                resultList.innerHTML += '<li class="loading-message">✅ ¡Descarga del ZIP completada!</li>';
                zipDownloadButton.disabled = false;
            })
            .catch(error => {
                resultList.innerHTML = `<li>❌ Error al generar el ZIP: ${error.message}</li>`;
                zipDownloadButton.disabled = false;
            });
        }
    
