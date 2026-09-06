document.addEventListener("DOMContentLoaded", function() {
    console.log("Biometric System Script Loaded Successfully");

    // 1. Digital Clock
    const clockDisplay = document.getElementById("clock");
    function runClock() {
        if (clockDisplay) {
            const now = new Date();
            clockDisplay.innerText = now.toLocaleTimeString();
        }
    }
    setInterval(runClock, 1000);
    runClock();

    // 2. Sidebar Controls
    const menuBtn = document.getElementById("menu-btn");
    const closeBtn = document.getElementById("close-btn");
    const sidebar = document.getElementById("sidebar");

    // Keep navigation visible on desktop; use the compact drawer pattern on phones.
    if (sidebar && window.matchMedia("(max-width: 700px)").matches) {
        sidebar.classList.add("collapsed");
    }

    if (menuBtn && sidebar) {
        menuBtn.onclick = () => {
            sidebar.classList.remove("collapsed");
            menuBtn.setAttribute("aria-expanded", "true");
        };
    }
    if (closeBtn && sidebar) {
        closeBtn.onclick = () => {
            sidebar.classList.add("collapsed");
            menuBtn?.setAttribute("aria-expanded", "false");
        };
    }

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && sidebar && !sidebar.classList.contains("collapsed")) {
            sidebar.classList.add("collapsed");
            menuBtn?.focus();
        }
    });

    // Public-site navigation collapses into a touch-friendly menu on small screens.
    const siteMenuBtn = document.querySelector(".site-menu-btn");
    const siteNav = document.querySelector(".nav-links");
    if (siteMenuBtn && siteNav) {
        siteMenuBtn.addEventListener("click", () => {
            const isOpen = siteNav.classList.toggle("is-open");
            siteMenuBtn.setAttribute("aria-expanded", String(isOpen));
        });
    }

    // 3. Live Dashboard Updates (AJAX)
    const dashboardTrigger = document.getElementById("stat-total");
    if (dashboardTrigger) {
        function syncStats() {
            fetch('get-stats.php')
                .then(res => res.json())
                .then(data => {
                    document.getElementById('stat-total').innerText = data.totalStudents;
                    document.getElementById('stat-today').innerText = data.attendanceToday;
                    
                    const camStatus = document.getElementById('stat-camera-text');
                    const camBadge = document.getElementById('stat-camera-pill');
                    
                    camStatus.innerText = data.activeCameras > 0 ? 'CONNECTED' : 'DISCONNECTED';
                    camStatus.style.color = data.statusColor;
                    
                    camBadge.innerText = data.activeCameras + " Device(s) Online";
                    camBadge.style.backgroundColor = data.activeCameras > 0 ? '#dcfce7' : '#fee2e2';
                    camBadge.style.color = data.activeCameras > 0 ? '#166534' : '#991b1b';
                })
                .catch(err => console.error("Sync Error:", err));
        }
        setInterval(syncStats, 5000); 
    }

    // 4. Line Chart Logic
    const ctx = document.getElementById('attendanceChart');
    if (ctx && typeof weeklyDataValues !== 'undefined') {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                datasets: [{
                    label: 'Attendance Count',
                    data: weeklyDataValues,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.1)',
                    borderWidth: 4,
                    tension: 0.3,
                    fill: true,
                    pointRadius: 5,
                    pointHoverRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // 5. Image Preview & Camera Logic
    const fileInput = document.getElementById("student_image");
    const previewImg = document.getElementById("main-preview");

    // Handle standard file upload preview
    if (fileInput) {
        fileInput.onchange = function() {
            const [file] = this.files;
            if (file) {
                const objectURL = URL.createObjectURL(file);
                previewImg.src = objectURL;
                previewImg.onload = () => URL.revokeObjectURL(objectURL);
                // Clear camera data if user picks a file
                const capturedInput = document.getElementById('captured_image');
                if(capturedInput) capturedInput.value = "";
            }
        };
    }

    // --- CAMERA TOGGLE LOGIC ---
    const toggleBtn = document.getElementById('btn-toggle-cam');
    const camSec = document.getElementById('camera-section');
    const upSec = document.getElementById('upload-section');
    const webcam = document.getElementById('webcam');
    const captureBtn = document.getElementById('capture-btn');
    const capturedInput = document.getElementById('captured_image');
    const canvas = document.getElementById('photo-canvas');

    let stream = null;

    if(toggleBtn) {
        toggleBtn.onclick = async () => {
            if (camSec.style.display === 'none') {
                camSec.style.display = 'block';
                upSec.style.display = 'none';
                toggleBtn.innerText = "📁 Switch to File Upload";
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: true });
                    webcam.srcObject = stream;
                } catch(e) { alert("Camera access denied."); }
            } else {
                camSec.style.display = 'none';
                upSec.style.display = 'block';
                toggleBtn.innerText = "📷 Use Laptop Camera Instead";
                if(stream) stream.getTracks().forEach(t => t.stop());
                capturedInput.value = ""; 
            }
        };
    }

    if(captureBtn) {
        captureBtn.onclick = () => {
            const context = canvas.getContext('2d');
            context.drawImage(webcam, 0, 0, canvas.width, canvas.height);
            const dataURL = canvas.toDataURL('image/jpeg');
            capturedInput.value = dataURL;
            previewImg.src = dataURL;
        };
    }

    // 6. Real-time Student Search
    const searchBox = document.querySelector(".search-box");
    if (searchBox) {
        searchBox.addEventListener("keyup", function() {
            const query = searchBox.value.toLowerCase();
            const tableRows = document.querySelectorAll(".student-table tbody tr");

            tableRows.forEach(row => {
                const studentID = row.cells[1].innerText.toLowerCase();
                const studentName = row.cells[2].innerText.toLowerCase();

                if (studentID.includes(query) || studentName.includes(query)) {
                    row.style.display = ""; 
                } else {
                    row.style.display = "none"; 
                }
            });
        });
    }

    // 7. Global Popup Functions
    window.showPopup = function(message) {
        const popup = document.getElementById("popup");
        const popupMsg = document.getElementById("popup-message");
        if (popup && popupMsg) {
            popupMsg.innerText = message;
            popup.classList.remove("hidden");
            popup.style.display = "flex"; 
        }
    };

    window.closePopup = function() {
        const popup = document.getElementById("popup");
        if (popup) {
            popup.classList.add("hidden");
            popup.style.display = "none";
        }
    };
});

