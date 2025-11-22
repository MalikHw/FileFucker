// Stars animation
const canvas = document.getElementById('stars');
const ctx = canvas.getContext('2d');
canvas.width = window.innerWidth;
canvas.height = window.innerHeight;

const stars = [];
for (let i = 0; i < 200; i++) {
    stars.push({
        x: Math.random() * canvas.width,
        y: Math.random() * canvas.height,
        radius: Math.random() * 1.5,
        speed: Math.random() * 0.5 + 0.1,
        opacity: Math.random()
    });
}

// Meteor effects
const meteors = [];
function createMeteor() {
    meteors.push({
        x: Math.random() * canvas.width,
        y: -50,
        length: Math.random() * 80 + 40,
        speed: Math.random() * 8 + 5,
        opacity: Math.random() * 0.5 + 0.5,
        angle: Math.random() * 30 + 60 // 60-90 degrees
    });
}

// Create meteors randomly
setInterval(() => {
    if (Math.random() < 0.3) { // 30% chance every interval
        createMeteor();
    }
}, 2000);

function drawStars() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Draw stars
    stars.forEach(star => {
        ctx.beginPath();
        ctx.arc(star.x, star.y, star.radius, 0, Math.PI * 2);
        ctx.fillStyle = `rgba(255, 255, 255, ${star.opacity})`;
        ctx.fill();
        
        star.y += star.speed;
        star.opacity = Math.abs(Math.sin(Date.now() * 0.001 + star.x));
        
        if (star.y > canvas.height) {
            star.y = 0;
            star.x = Math.random() * canvas.width;
        }
    });
    
    // Draw meteors
    meteors.forEach((meteor, index) => {
        const angle = (meteor.angle * Math.PI) / 180;
        const endX = meteor.x + Math.cos(angle) * meteor.length;
        const endY = meteor.y + Math.sin(angle) * meteor.length;
        
        // Create gradient for meteor tail
        const gradient = ctx.createLinearGradient(meteor.x, meteor.y, endX, endY);
        gradient.addColorStop(0, `rgba(255, 255, 255, ${meteor.opacity})`);
        gradient.addColorStop(0.5, `rgba(131, 56, 236, ${meteor.opacity * 0.6})`);
        gradient.addColorStop(1, 'rgba(255, 0, 110, 0)');
        
        ctx.beginPath();
        ctx.strokeStyle = gradient;
        ctx.lineWidth = 2;
        ctx.moveTo(meteor.x, meteor.y);
        ctx.lineTo(endX, endY);
        ctx.stroke();
        
        // Move meteor
        meteor.x += Math.cos(angle) * meteor.speed;
        meteor.y += Math.sin(angle) * meteor.speed;
        
        // Remove meteor if off screen
        if (meteor.y > canvas.height + 100 || meteor.x > canvas.width + 100) {
            meteors.splice(index, 1);
        }
    });
    
    requestAnimationFrame(drawStars);
}
drawStars();

window.addEventListener('resize', () => {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
});

// File upload functionality (only run on upload page)
const uploadArea = document.getElementById('uploadArea');
const fileInput = document.getElementById('fileInput');
const loader = document.getElementById('loader');
const result = document.getElementById('result');
const uploadProgress = document.getElementById('uploadProgress');
const uploadProgressFill = document.getElementById('uploadProgressFill');
const uploadPercent = document.getElementById('uploadPercent');

if (uploadArea && fileInput) {
    uploadArea.addEventListener('click', () => fileInput.click());
    
    uploadArea.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadArea.classList.add('dragover');
    });
    
    uploadArea.addEventListener('dragleave', () => {
        uploadArea.classList.remove('dragover');
    });
    
    uploadArea.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            handleFile(files[0]);
        }
    });
    
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            handleFile(e.target.files[0]);
        }
    });
}

function handleFile(file) {
    if (file.size > 100 * 1024 * 1024) {
        showResult('File is too fucking big! Max 100MB.', true);
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    
    result.style.display = 'none';
    uploadProgress.style.display = 'block';
    
    // Use XMLHttpRequest for progress tracking
    const xhr = new XMLHttpRequest();
    
    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            const percentComplete = (e.loaded / e.total) * 100;
            uploadProgressFill.style.width = percentComplete + '%';
            uploadPercent.textContent = Math.round(percentComplete) + '%';
        }
    });
    
    xhr.addEventListener('load', () => {
        uploadProgress.style.display = 'none';
        
        if (xhr.status === 200) {
            try {
                const data = JSON.parse(xhr.responseText);
                if (data.success) {
                    const expiryText = file.size < 1024 * 1024 ? '1 month' : 
                                     (file.size < 2 * 1024 * 1024 ? '1 week' : '48 hours');
                    showResult(`
                        <h3><i class="nf nf-fa-check_circle"></i> Your shit is uploaded!</h3>
                        <div class="link-box">
                            <input type="text" class="link-input" value="${data.url}" id="linkInput" readonly>
                            <button class="copy-btn" onclick="copyLink()"><i class="nf nf-fa-copy"></i> Copy</button>
                        </div>
                        <div class="info-box">
                            <i class="nf nf-fa-bar_chart"></i> Download limit: 3 times<br>
                            <i class="nf nf-fa-clock_o"></i> Expires: ${expiryText} after last download<br>
                            <i class="nf nf-fa-fire"></i> Share this link before it's gone!
                        </div>
                    `);
                } else {
                    showResult(data.error || 'Upload failed, try again!', true);
                }
            } catch (e) {
                showResult('Something fucked up. Try again!', true);
            }
        } else {
            showResult('Upload failed. Server returned error.', true);
        }
    });
    
    xhr.addEventListener('error', () => {
        uploadProgress.style.display = 'none';
        showResult('Something fucked up. Try again!', true);
    });
    
    xhr.open('POST', '');
    xhr.send(formData);
}

function showResult(html, isError = false) {
    result.innerHTML = html;
    result.style.display = 'block';
    result.className = 'result' + (isError ? ' error' : '');
}

function copyLink() {
    const linkInput = document.getElementById('linkInput');
    linkInput.select();
    document.execCommand('copy');
    
    const btn = event.target.closest('.copy-btn');
    const originalHTML = btn.innerHTML;
    btn.innerHTML = '<i class="nf nf-fa-check"></i> Copied!';
    setTimeout(() => {
        btn.innerHTML = originalHTML;
    }, 2000);
}

// Download progress tracking (for preview page)
const downloadBtn = document.getElementById('downloadBtn');
if (downloadBtn) {
    downloadBtn.addEventListener('click', function(e) {
        e.preventDefault();
        
        const url = this.href;
        const fileSize = parseInt(this.dataset.size);
        const filename = this.dataset.filename;
        
        const downloadProgress = document.getElementById('downloadProgress');
        const downloadSize = document.getElementById('downloadSize');
        const downloadTime = document.getElementById('downloadTime');
        const downloadProgressFill = document.getElementById('downloadProgressFill');
        
        downloadProgress.style.display = 'block';
        this.style.display = 'none';
        
        const xhr = new XMLHttpRequest();
        let startTime = Date.now();
        let lastLoaded = 0;
        let speeds = [];
        
        xhr.open('GET', url, true);
        xhr.responseType = 'blob';
        
        xhr.addEventListener('progress', (e) => {
            if (e.lengthComputable) {
                const percentComplete = (e.loaded / e.total) * 100;
                downloadProgressFill.style.width = percentComplete + '%';
                
                const loadedMB = (e.loaded / 1024 / 1024).toFixed(2);
                const totalMB = (e.total / 1024 / 1024).toFixed(2);
                downloadSize.textContent = `${loadedMB} MB / ${totalMB} MB`;
                
                // Calculate speed and ETA
                const elapsed = (Date.now() - startTime) / 1000; // seconds
                const speed = e.loaded / elapsed; // bytes per second
                speeds.push(speed);
                
                // Average speed over last few measurements
                const avgSpeed = speeds.slice(-5).reduce((a, b) => a + b) / Math.min(speeds.length, 5);
                const remaining = e.total - e.loaded;
                const eta = remaining / avgSpeed;
                
                if (eta < 60) {
                    downloadTime.textContent = `${Math.ceil(eta)} seconds left`;
                } else {
                    downloadTime.textContent = `${Math.ceil(eta / 60)} minutes left`;
                }
            }
        });
        
        xhr.addEventListener('load', () => {
            if (xhr.status === 200) {
                // Create download link
                const blob = xhr.response;
                const downloadUrl = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = downloadUrl;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(downloadUrl);
                
                downloadSize.textContent = 'Download complete!';
                downloadTime.textContent = '';
                
                setTimeout(() => {
                    location.reload(); // Reload to show updated download count
                }, 1500);
            } else {
                downloadProgress.style.display = 'none';
                downloadBtn.style.display = 'block';
                alert('Download failed. Try again!');
            }
        });
        
        xhr.addEventListener('error', () => {
            downloadProgress.style.display = 'none';
            downloadBtn.style.display = 'block';
            alert('Download failed. Try again!');
        });
        
        xhr.send();
    });
}