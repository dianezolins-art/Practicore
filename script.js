/**
 * Practicore - Client Application Logic
 * Landing Page & Community Review Hub
 */

document.addEventListener("DOMContentLoaded", () => {
    console.log("Practicore Portal Hub initialized.");
});

/**
 * Handle Public Search on Landing Page
 */
function handlePublicSearch() {
    const school = (document.getElementById("publicSchoolInput").value || "").trim();
    const course = (document.getElementById("publicCourseInput").value || "").trim();

    const titleEl = document.getElementById("promptModalTitle");
    const descEl = document.getElementById("promptModalDesc");

    let queryText = "";
    if (school && course) {
        queryText = `${school} for ${course}`;
    } else if (school) {
        queryText = school;
    } else if (course) {
        queryText = course;
    } else {
        queryText = "your designated school";
    }

    if (titleEl && descEl) {
        titleEl.textContent = `Verified Openings for ${queryText}!`;
        descEl.textContent = `Host companies have published active OJT slots designated for students of ${queryText}. To preserve verified academic compliance and submit your MoA requirements, please log in to your student dashboard.`;
    }

    const modal = new bootstrap.Modal(document.getElementById("searchPromptModal"));
    modal.show();
}

/**
 * Quick search shortcut tags
 */
function setQuickSearch(school, course) {
    document.getElementById("publicSchoolInput").value = school;
    document.getElementById("publicCourseInput").value = course;
    handlePublicSearch();
}

/**
 * Open Write Review Modal
 */
function openReviewModal() {
    const modal = new bootstrap.Modal(document.getElementById("reviewModal"));
    modal.show();
}

/**
 * Submit New User Review (Dynamically adds to page)
 */
function submitReview() {
    const name = document.getElementById("reviewName").value.trim();
    const role = document.getElementById("reviewRole").value;
    const affiliation = document.getElementById("reviewAffiliation").value.trim();
    const stars = parseInt(document.getElementById("reviewStars").value, 10);
    const comment = document.getElementById("reviewComment").value.trim();

    if (!name || !comment) return;

    // Build star icons string
    let starsHtml = "";
    for (let i = 0; i < stars; i++) {
        starsHtml += '<i class="bi bi-star-fill"></i> ';
    }
    for (let i = stars; i < 5; i++) {
        starsHtml += '<i class="bi bi-star"></i> ';
    }

    // Initials for avatar
    const initials = name.split(" ").map(n => n[0]).join("").substring(0, 2).toUpperCase();

    // Create card element
    const col = document.createElement("div");
    col.className = "col-lg-4 col-md-6";
    col.innerHTML = `
        <div class="glass-card review-card" style="border-color: rgba(99, 102, 241, 0.4); animation: fadeIn 0.5s ease;">
            <div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="star-rating mb-0">
                        ${starsHtml}
                    </div>
                    <span class="badge bg-success bg-opacity-25 text-success verified-badge">
                        <i class="bi bi-patch-check-fill"></i> Verified Review
                    </span>
                </div>
                <p class="review-comment">"${escapeHtml(comment)}"</p>
            </div>
            <div class="reviewer-meta">
                <div class="reviewer-avatar" style="background: linear-gradient(135deg, #10b981, #06b6d4);">${initials}</div>
                <div>
                    <div class="reviewer-name">${escapeHtml(name)}</div>
                    <div class="reviewer-sub">${escapeHtml(role)} • ${escapeHtml(affiliation)}</div>
                </div>
            </div>
        </div>
    `;

    const container = document.getElementById("reviewsContainer");
    if (container) {
        container.prepend(col);
    }

    // Close modal
    const modalEl = document.getElementById("reviewModal");
    const modal = bootstrap.Modal.getInstance(modalEl);
    if (modal) modal.hide();

    // Reset form
    document.getElementById("reviewForm").reset();

    // Trigger toast
    showToast(
        "Review Posted!",
        `Thank you ${name}! Your rating has been shared with the Practicore community.`,
        "success"
    );
}

/**
 * Custom Glass Toast Notification
 */
function showToast(title, message, type = "info") {
    const container = document.getElementById("toastContainer");
    if (!container) return;

    let iconClass = "bi-info-circle-fill text-info";
    if (type === "success") iconClass = "bi-check-circle-fill text-success";
    if (type === "warning") iconClass = "bi-exclamation-triangle-fill text-warning";

    const toast = document.createElement("div");
    toast.className = "toast-glass";
    toast.innerHTML = `
        <i class="bi ${iconClass} fs-4"></i>
        <div style="flex: 1;">
            <div class="fw-bold small text-white">${title}</div>
            <div class="text-muted small" style="font-size: 0.82rem;">${message}</div>
        </div>
        <button type="button" class="btn-close btn-close-white btn-sm" onclick="this.parentElement.remove()"></button>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.transition = "opacity 0.4s ease, transform 0.4s ease";
        toast.style.opacity = "0";
        toast.style.transform = "translateX(50px)";
        setTimeout(() => toast.remove(), 400);
    }, 4000);
}

function escapeHtml(text) {
    const div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
}
