/**
 * MediSeba - Main Application JavaScript
 * 
 * Handles common functionality across all pages
 */

// HTML escape utility to prevent XSS
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

const THEME_STORAGE_KEY = 'mediseba_theme';
const TESTIMONIAL_ROTATION_MS = 7000;
const FAVICON_SVG_PATH = resolvePagePath('images/logo-mark.svg');
const FAVICON_PNG_PATH = resolvePagePath('images/hero-brand.png');

let testimonialRotationTimer = null;
let activeTestimonialIndex = 0;
let testimonialsData = [];

function getSystemTheme() {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') {
        return 'light';
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function getStoredTheme() {
    try {
        const theme = localStorage.getItem(THEME_STORAGE_KEY);
        return theme === 'dark' || theme === 'light' ? theme : null;
    } catch (error) {
        return null;
    }
}

function getPreferredTheme() {
    return getStoredTheme() || 'light';
}

function updateThemeToggleButton(theme = getPreferredTheme()) {
    const toggle = document.getElementById('themeToggle');
    if (!toggle) {
        return;
    }

    const nextTheme = theme === 'dark' ? 'light' : 'dark';
    const icon = theme === 'dark' ? 'fa-sun' : 'fa-moon';
    const label = theme === 'dark' ? 'Dark Mode' : 'Light Mode';
    const hint = theme === 'dark' ? 'Switch to light' : 'Switch to dark';

    toggle.setAttribute('aria-label', hint);
    toggle.setAttribute('title', hint);
    toggle.dataset.theme = theme;
    toggle.innerHTML = `
        <span class="theme-toggle-icon" aria-hidden="true">
            <i class="fas ${icon}"></i>
        </span>
        <span class="theme-toggle-text">
            <span class="theme-toggle-label">${label}</span>
            <span class="theme-toggle-mode">${hint}</span>
        </span>
    `;
    toggle.dataset.nextTheme = nextTheme;
}

function applyTheme(theme, persist = true) {
    const resolvedTheme = theme === 'dark' ? 'dark' : 'light';

    if (typeof document !== 'undefined') {
        document.documentElement.dataset.theme = resolvedTheme;
        document.documentElement.style.colorScheme = resolvedTheme;
    }

    if (persist) {
        try {
            localStorage.setItem(THEME_STORAGE_KEY, resolvedTheme);
        } catch (error) {
            console.warn('Unable to save theme preference:', error);
        }
    }

    updateThemeToggleButton(resolvedTheme);
    return resolvedTheme;
}

function ensureFavicon() {
    if (typeof document === 'undefined' || !document.head) {
        return;
    }

    const faviconLinks = [
        {
            selector: 'link[data-mediseba-favicon="svg"]',
            rel: 'icon',
            type: 'image/svg+xml',
            href: FAVICON_SVG_PATH,
            token: 'svg'
        },
        {
            selector: 'link[data-mediseba-favicon="png"]',
            rel: 'icon',
            type: 'image/png',
            href: FAVICON_PNG_PATH,
            token: 'png'
        }
    ];

    faviconLinks.forEach((config) => {
        let link = document.head.querySelector(config.selector);

        if (!link) {
            link = document.createElement('link');
            link.setAttribute('data-mediseba-favicon', config.token);
            document.head.appendChild(link);
        }

        link.rel = config.rel;
        link.type = config.type;
        link.href = config.href;
    });
}

function toggleTheme() {
    const nextTheme = (document.documentElement.dataset.theme || getPreferredTheme()) === 'dark'
        ? 'light'
        : 'dark';
    applyTheme(nextTheme);
}

function ensureThemeToggle() {
    if (typeof document === 'undefined' || document.getElementById('themeToggle')) {
        return;
    }

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.id = 'themeToggle';
    toggle.className = 'theme-toggle';
    toggle.addEventListener('click', toggleTheme);
    document.body.appendChild(toggle);

    updateThemeToggleButton(document.documentElement.dataset.theme || getPreferredTheme());
}

applyTheme(getPreferredTheme(), false);

function resolveAssetPath(path, fallback = '') {
    const candidate = String(path || '').trim().replace(/\\/g, '/');

    if (!candidate) {
        if (!fallback) return fallback;
        // Also resolve fallback through base path
        return resolveAssetPath(fallback);
    }

    if (/^(https?:)?\/\//i.test(candidate) || candidate.startsWith('/') || candidate.startsWith('data:')) {
        return candidate;
    }

    const normalizedCandidate = candidate.replace(/^\.\//, '');
    const pathname = typeof window !== 'undefined' ? (window.location.pathname || '') : '';

    if (normalizedCandidate.startsWith('frontend/') && pathname.includes('/frontend/')) {
        return normalizedCandidate.slice('frontend/'.length);
    }

    // Use APP_BASE_PATH to create an absolute-from-root path for assets
    const base = (typeof APP_BASE_PATH !== 'undefined' ? APP_BASE_PATH : '') || '';
    return `${base}/${normalizedCandidate}`.replace(/\/{2,}/g, '/');
}

function resolveLegacyAssetPath(path) {
    const normalizedPath = String(path || '').trim().replace(/\\/g, '/').replace(/^\.\//, '');

    if (!normalizedPath || /^(https?:)?\/\//i.test(normalizedPath) || normalizedPath.startsWith('/') || normalizedPath.startsWith('data:')) {
        return '';
    }

    const pathname = typeof window !== 'undefined' ? (window.location.pathname || '') : '';
    const isFrontendDeployment = pathname.includes('/frontend/');

    if (isFrontendDeployment) {
        return '';
    }

    if (normalizedPath.startsWith('uploads/')) {
        return `frontend/${normalizedPath}`;
    }

    return '';
}

function handleAssetImageError(image, fallback = '') {
    if (!(image instanceof HTMLImageElement)) {
        return;
    }

    const legacySrc = image.dataset.legacySrc || '';
    if (legacySrc && !image.dataset.triedLegacy) {
        image.dataset.triedLegacy = '1';
        image.src = legacySrc;
        return;
    }

    const fallbackSrc = resolveAssetPath(fallback);
    if (fallbackSrc && !image.dataset.triedFallback) {
        image.dataset.triedFallback = '1';
        image.src = fallbackSrc;
        return;
    }

    const parent = image.parentElement;
    if (parent && (parent.classList.contains('user-avatar') || parent.classList.contains('profile-photo-preview'))) {
        const displayName = image.dataset.name || parent.dataset.name || '';
        const fallbackIcon = image.dataset.fallbackIcon || parent.dataset.fallbackIcon || 'fa-user';
        const initial = buildAvatarInitial(displayName);

        parent.classList.remove('has-photo');
        parent.innerHTML = initial || `<i class="fas ${escapeHtml(fallbackIcon)}"></i>`;
        return;
    }

    image.removeAttribute('src');
}

function buildAvatarInitial(name, fallback = '') {
    const value = String(name || '').trim();
    return value ? value.charAt(0).toUpperCase() : fallback;
}

function renderUserAvatar(target, options = {}) {
    const element = typeof target === 'string' ? document.getElementById(target) : target;
    if (!element) {
        return;
    }

    const {
        name = '',
        photoPath = '',
        fallbackIcon = 'fa-user'
    } = options;

    const resolvedPhotoPath = resolveAssetPath(photoPath);
    const legacyPhotoPath = resolveLegacyAssetPath(photoPath);

    if (resolvedPhotoPath) {
        element.classList.add('has-photo');
        element.dataset.name = name;
        element.dataset.fallbackIcon = fallbackIcon;
        element.innerHTML = `<img src="${escapeHtml(resolvedPhotoPath)}" alt="${escapeHtml(name || 'Profile photo')}" ${legacyPhotoPath ? `data-legacy-src="${escapeHtml(legacyPhotoPath)}"` : ''} data-name="${escapeHtml(name || '')}" data-fallback-icon="${escapeHtml(fallbackIcon)}" onerror="handleAssetImageError(this)">`;
        return;
    }

    element.classList.remove('has-photo');

    const initial = buildAvatarInitial(name);
    element.innerHTML = initial || `<i class="fas ${escapeHtml(fallbackIcon)}"></i>`;
}

function syncStoredUserProfile(user = {}, profile = {}) {
    if (typeof Auth === 'undefined' || typeof Auth.getUser !== 'function') {
        return { ...user };
    }

    const currentUser = Auth.getUser() || {};
    const mergedUser = {
        ...currentUser,
        ...user
    };

    if (profile.full_name) {
        mergedUser.full_name = profile.full_name;
    }

    if (Object.prototype.hasOwnProperty.call(profile, 'profile_photo')) {
        mergedUser.profile_photo = profile.profile_photo || null;
    }

    Auth.setUser(mergedUser);
    return mergedUser;
}

function updateSidebarIdentity(options = {}) {
    const {
        user = {},
        profile = {},
        nameTarget = 'userName',
        avatarTarget = 'userAvatar',
        fallbackName = 'User',
        fallbackIcon = 'fa-user'
    } = options;

    const mergedUser = syncStoredUserProfile(user, profile);
    const displayName = profile.full_name || mergedUser.full_name || mergedUser.email || fallbackName;
    const photoPath = profile.profile_photo || mergedUser.profile_photo || '';

    const nameElement = typeof nameTarget === 'string' ? document.getElementById(nameTarget) : nameTarget;
    if (nameElement) {
        nameElement.textContent = displayName;
    }

    renderUserAvatar(avatarTarget, {
        name: displayName,
        photoPath,
        fallbackIcon
    });

    return {
        user: mergedUser,
        displayName,
        photoPath
    };
}

function toggleSidebar() {
    const shouldOpen = !document.getElementById('sidebar')?.classList.contains('active');
    setDashboardSidebarState(shouldOpen);
}

function setDashboardSidebarState(isOpen) {
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const navToggle = document.getElementById('navToggle');

    if (sidebar) {
        sidebar.classList.toggle('active', Boolean(isOpen));
    }

    if (sidebarOverlay) {
        sidebarOverlay.classList.toggle('active', Boolean(isOpen));
    }

    if (navToggle) {
        navToggle.setAttribute('aria-expanded', String(Boolean(isOpen)));
    }

    if (window.innerWidth <= 768) {
        document.body.classList.toggle('sidebar-open', Boolean(isOpen));
    } else {
        document.body.classList.remove('sidebar-open');
    }
}

function initDashboardNavToggle() {
    const navToggle = document.getElementById('navToggle');
    if (!navToggle) {
        return;
    }

    const sidebar = document.getElementById('sidebar');
    const handleViewportChange = () => {
        const isMobile = window.innerWidth <= 768;
        navToggle.style.display = isMobile ? 'inline-flex' : 'none';
        navToggle.setAttribute('aria-controls', 'sidebar');
        navToggle.setAttribute('aria-expanded', sidebar?.classList.contains('active') ? 'true' : 'false');

        if (!isMobile) {
            setDashboardSidebarState(false);
        }
    };

    if (!navToggle.dataset.sidebarBound) {
        navToggle.dataset.sidebarBound = 'true';
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setDashboardSidebarState(false);
            }
        });

        if (sidebar) {
            sidebar.addEventListener('click', (event) => {
                if (window.innerWidth > 768) {
                    return;
                }

                if (event.target.closest('a, button.btn')) {
                    setDashboardSidebarState(false);
                }
            });
        }
    }

    window.addEventListener('resize', handleViewportChange);
    handleViewportChange();
}

async function loadSidebarIdentity(role = 'patient', options = {}) {
    const normalizedRole = role === 'doctor' ? 'doctor' : 'patient';
    const fallbackName = normalizedRole === 'doctor' ? 'Doctor' : 'Patient';
    const fallbackIcon = normalizedRole === 'doctor' ? 'fa-user-md' : 'fa-user';
    const retries = Math.max(0, Number(options.retries || 0));
    const retryDelay = Math.max(0, Number(options.retryDelay || 300));
    const user = Auth.getUser() || {};

    updateSidebarIdentity({
        user,
        fallbackName,
        fallbackIcon
    });

    for (let attempt = 0; attempt <= retries; attempt += 1) {
        try {
            const result = normalizedRole === 'doctor'
                ? await API.doctors.profile()
                : await API.auth.me();

            const nextUser = normalizedRole === 'doctor'
                ? user
                : (result.user || user);
            const profile = normalizedRole === 'doctor'
                ? (result.doctor || {})
                : (result.profile || {});

            updateSidebarIdentity({
                user: nextUser,
                profile,
                fallbackName,
                fallbackIcon
            });

            return result;
        } catch (error) {
            if (attempt < retries) {
                await new Promise((resolve) => {
                    window.setTimeout(resolve, retryDelay * (attempt + 1));
                });
                continue;
            }

            console.warn(`Unable to refresh ${normalizedRole} identity:`, error);
            return null;
        }
    }

    return null;
}

// Mobile Navigation Toggle
function initMobileNav() {
    const navToggle = document.getElementById('navToggle');
    const navMenu = document.getElementById('navMenu');
    
    if (navToggle && navMenu) {
        navToggle.setAttribute('aria-controls', navMenu.id || 'navMenu');
        navToggle.setAttribute('aria-expanded', 'false');

        const closeMenu = () => {
            navMenu.classList.remove('active');
            navToggle.classList.remove('active');
            navToggle.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('nav-open');
        };

        const openMenu = () => {
            navMenu.classList.add('active');
            navToggle.classList.add('active');
            navToggle.setAttribute('aria-expanded', 'true');
            document.body.classList.add('nav-open');
        };

        navToggle.addEventListener('click', () => {
            if (navMenu.classList.contains('active')) {
                closeMenu();
                return;
            }

            openMenu();
        });

        navMenu.addEventListener('click', (e) => {
            if (e.target.closest('a')) {
                closeMenu();
            }
        });
        
        document.addEventListener('click', (e) => {
            if (!navToggle.contains(e.target) && !navMenu.contains(e.target)) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeMenu();
            }
        });

        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                closeMenu();
            }
        });
    }
}

function makeIconsDecorative() {
    document.querySelectorAll('i[class*="fa-"]').forEach((icon) => {
        if (icon.hasAttribute('aria-label')) {
            return;
        }

        const interactiveParent = icon.closest('button, a');
        if (interactiveParent) {
            const accessibleName = interactiveParent.getAttribute('aria-label')
                || interactiveParent.textContent.trim();

            if (accessibleName) {
                icon.setAttribute('aria-hidden', 'true');
            }

            return;
        }

        icon.setAttribute('aria-hidden', 'true');
    });
}

function renderTestimonialStars(rating = 5) {
    const safeRating = Math.max(1, Math.min(5, Number(rating) || 5));
    return Array.from({ length: 5 }, (_, index) => {
        const starClass = index < safeRating ? 'fas fa-star' : 'far fa-star';
        return `<i class="${starClass}" aria-hidden="true"></i>`;
    }).join('');
}

function normalizeDoctorDisplayName(name = '') {
    const value = String(name || '').trim();
    if (!value) {
        return 'Doctor';
    }

    return /^dr\.?\s+/i.test(value) ? value : `Dr. ${value}`;
}

function handleTestimonialAvatarError(image) {
    if (!(image instanceof HTMLImageElement)) {
        return;
    }

    const legacySrc = image.dataset.legacySrc || '';
    if (legacySrc && !image.dataset.triedLegacy) {
        image.dataset.triedLegacy = '1';
        image.src = legacySrc;
        return;
    }

    const avatar = image.closest('.testimonial-avatar');
    if (!avatar) {
        return;
    }

    avatar.classList.remove('has-photo');
    avatar.textContent = image.dataset.initials || 'VP';
}

function renderTestimonialAvatar(testimonial = {}) {
    const initials = String(testimonial.initials || 'VP').trim() || 'VP';
    const photoPath = testimonial.photoPath || '';
    const resolvedPhotoPath = resolveAssetPath(photoPath);
    const legacyPhotoPath = resolveLegacyAssetPath(photoPath);

    if (!resolvedPhotoPath) {
        return `<span class="testimonial-avatar" aria-hidden="true">${escapeHtml(initials)}</span>`;
    }

    return `
        <span class="testimonial-avatar has-photo" aria-hidden="true">
            <img
                src="${escapeHtml(resolvedPhotoPath)}"
                alt="${escapeHtml(testimonial.name || 'Verified patient')}"
                ${legacyPhotoPath ? `data-legacy-src="${escapeHtml(legacyPhotoPath)}"` : ''}
                data-initials="${escapeHtml(initials)}"
                onerror="handleTestimonialAvatarError(this)">
        </span>
    `;
}

function stopTestimonialRotation() {
    if (testimonialRotationTimer) {
        window.clearInterval(testimonialRotationTimer);
        testimonialRotationTimer = null;
    }
}

function setTestimonialsVisibility(isVisible) {
    const section = document.getElementById('testimonialsSection');
    if (!section) {
        return;
    }

    section.hidden = !isVisible;
}

function updateTestimonialUI(index = 0) {
    const spotlight = document.getElementById('testimonialSpotlight');
    const grid = document.getElementById('testimonialsGrid');
    const dots = document.getElementById('testimonialDots');

    if (!spotlight || !grid || !dots || testimonialsData.length === 0) {
        return;
    }

    const normalizedIndex = ((index % testimonialsData.length) + testimonialsData.length) % testimonialsData.length;
    const testimonial = testimonialsData[normalizedIndex];
    activeTestimonialIndex = normalizedIndex;

    spotlight.innerHTML = `
        <div class="testimonial-rating" aria-label="${escapeHtml(String(testimonial.rating))} out of 5 stars">
            ${renderTestimonialStars(testimonial.rating)}
        </div>
        <blockquote class="testimonial-quote">"${escapeHtml(testimonial.quote)}"</blockquote>
        <div class="testimonial-author">
            ${renderTestimonialAvatar(testimonial)}
            <div>
                <h3>${escapeHtml(testimonial.name)}</h3>
                <p>${escapeHtml(testimonial.meta)}</p>
            </div>
        </div>
    `;

    grid.innerHTML = testimonialsData.map((item, itemIndex) => `
        <article class="testimonial-card ${itemIndex === normalizedIndex ? 'active' : ''}">
            <div class="testimonial-card-header">
                <div>
                    <h3>${escapeHtml(item.name)}</h3>
                    <p class="testimonial-card-meta">${escapeHtml(item.meta)}</p>
                </div>
                ${renderTestimonialAvatar(item)}
            </div>
            <p>${escapeHtml(item.quote)}</p>
        </article>
    `).join('');

    dots.innerHTML = testimonialsData.map((item, itemIndex) => `
        <button
            type="button"
            class="testimonial-dot ${itemIndex === normalizedIndex ? 'active' : ''}"
            aria-label="Show testimonial from ${escapeHtml(item.name)}"
            data-index="${itemIndex}">
        </button>
    `).join('');

    dots.querySelectorAll('.testimonial-dot').forEach((button) => {
        button.addEventListener('click', () => {
            updateTestimonialUI(Number(button.dataset.index));
            startTestimonialRotation();
        });
    });
}

function startTestimonialRotation() {
    stopTestimonialRotation();

    if (testimonialsData.length <= 1) {
        return;
    }

    testimonialRotationTimer = window.setInterval(() => {
        updateTestimonialUI(activeTestimonialIndex + 1);
    }, TESTIMONIAL_ROTATION_MS);
}

async function initTestimonials() {
    const spotlight = document.getElementById('testimonialSpotlight');
    const grid = document.getElementById('testimonialsGrid');
    const dots = document.getElementById('testimonialDots');
    const prev = document.getElementById('testimonialPrev');
    const next = document.getElementById('testimonialNext');

    if (!spotlight || !grid || !dots || !prev || !next) {
        stopTestimonialRotation();
        setTestimonialsVisibility(false);
        return;
    }

    try {
        const reviews = await API.doctors.testimonials({ limit: 4 });
        testimonialsData = Array.isArray(reviews)
            ? reviews.map((review) => ({
                quote: review.quote || '',
                name: review.patient_name || 'Verified Patient',
                meta: `${review.specialty} consultation with ${normalizeDoctorDisplayName(review.doctor_name)}`,
                rating: review.rating || 5,
                initials: review.patient_initials || 'VP',
                photoPath: review.patient_profile_photo || ''
            })).filter((review) => review.quote.trim() !== '')
            : [];
    } catch (error) {
        console.warn('Unable to load testimonials:', error);
        testimonialsData = [];
    }

    if (testimonialsData.length === 0) {
        stopTestimonialRotation();
        setTestimonialsVisibility(false);
        return;
    }

    setTestimonialsVisibility(true);

    prev.addEventListener('click', () => {
        updateTestimonialUI(activeTestimonialIndex - 1);
        startTestimonialRotation();
    });

    next.addEventListener('click', () => {
        updateTestimonialUI(activeTestimonialIndex + 1);
        startTestimonialRotation();
    });

    updateTestimonialUI(0);
    startTestimonialRotation();
}

function formatHeroCount(value) {
    const numericValue = Number(value);

    if (!Number.isFinite(numericValue) || numericValue < 0) {
        return '--';
    }

    return new Intl.NumberFormat('en-US').format(Math.round(numericValue));
}

function formatHeroRating(value) {
    const numericValue = Number(value);

    if (!Number.isFinite(numericValue) || numericValue < 0) {
        return '--';
    }

    return numericValue.toFixed(1);
}

function formatDoctorAverageRating(value) {
    const numericValue = Number(value);

    if (!Number.isFinite(numericValue) || numericValue <= 0) {
        return '0.0';
    }

    return numericValue.toFixed(1);
}

function getDoctorRatingDisplay(doctor = {}) {
    const totalReviews = Math.max(0, Number(doctor.total_reviews) || 0);

    if (totalReviews < 1) {
        return {
            hasReviews: false,
            value: 'New',
            meta: 'No patient reviews yet',
            reviewCountLabel: 'No reviews yet'
        };
    }

    return {
        hasReviews: true,
        value: formatDoctorAverageRating(doctor.average_rating),
        meta: `${totalReviews} review${totalReviews === 1 ? '' : 's'}`,
        reviewCountLabel: `${totalReviews} review${totalReviews === 1 ? '' : 's'}`
    };
}

function setHeroStat(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function setHeroPatientLabel(count) {
    const label = document.getElementById('heroPatientLabel');
    if (label) {
        label.textContent = count === 1 ? 'Patient' : 'Patients';
    }
}

async function loadHeroStats() {
    const heroPatientCount = document.getElementById('heroPatientCount');
    const heroVerifiedDoctors = document.getElementById('heroVerifiedDoctors');
    const heroAppointments = document.getElementById('heroAppointments');
    const heroAverageRating = document.getElementById('heroAverageRating');

    if (!heroPatientCount && !heroVerifiedDoctors && !heroAppointments && !heroAverageRating) {
        return;
    }

    try {
        const stats = await API.doctors.publicStats();
        const patientCount = Math.max(0, Number(stats.total_patients) || 0);

        setHeroStat('heroPatientCount', formatHeroCount(patientCount));
        setHeroPatientLabel(patientCount);
        setHeroStat('heroVerifiedDoctors', formatHeroCount(stats.verified_doctors));
        setHeroStat('heroAppointments', formatHeroCount(stats.total_appointments));
        setHeroStat('heroAverageRating', formatHeroRating(stats.average_rating));
    } catch (error) {
        console.warn('Unable to load hero statistics:', error);
    }
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        stopTestimonialRotation();
        return;
    }

    const spotlight = document.getElementById('testimonialSpotlight');
    if (spotlight && testimonialsData.length > 0) {
        startTestimonialRotation();
    }
});

// Load Specialties
async function loadSpecialties() {
    const container = document.getElementById('specialtiesGrid');
    if (!container) return;
    
    try {
        const specialties = await API.doctors.specialties();
        
        if (!specialties || specialties.length === 0) {
            container.innerHTML = '<p class="text-center">No specialties available</p>';
            return;
        }
        
        const specialtyIcons = {
            'Cardiology': 'fa-heart-pulse',
            'Gynecology': 'fa-baby',
            'Orthopedics': 'fa-bone',
            'Dermatology': 'fa-hand-dots',
            'Pediatrics': 'fa-baby-carriage',
            'Neurology': 'fa-brain',
            'Ophthalmology': 'fa-eye',
            'ENT': 'fa-ear-listen',
            'Dentistry': 'fa-tooth',
            'Psychiatry': 'fa-head-side-virus',
            'General Physician': 'fa-user-doctor',
            'default': 'fa-stethoscope'
        };
        
        container.innerHTML = specialties.slice(0, 8).map(specialty => {
            const icon = specialtyIcons[specialty.specialty] || specialtyIcons.default;
            return `
                <a href="${resolvePagePath('pages/doctors.html?specialty=' + encodeURIComponent(specialty.specialty))}" class="specialty-card" aria-label="Browse ${escapeHtml(specialty.specialty)} doctors">
                    <i class="fas ${escapeHtml(icon)} specialty-icon" aria-hidden="true"></i>
                    <h3>${escapeHtml(specialty.specialty)}</h3>
                    <p>${escapeHtml(specialty.doctor_count)} Doctors</p>
                </a>
            `;
        }).join('');
        
    } catch (error) {
        console.error('Failed to load specialties:', error);
        container.innerHTML = '<p class="text-center">Failed to load specialties</p>';
    }
}

// Load Featured Doctors
async function loadFeaturedDoctors() {
    const container = document.getElementById('featuredDoctors');
    if (!container) return;
    
    try {
        const doctors = await API.doctors.featured();
        
        if (!doctors || doctors.length === 0) {
            container.innerHTML = '<p class="text-center">No featured doctors available</p>';
            return;
        }
        
        container.innerHTML = doctors.map(doctor => {
            const rating = getDoctorRatingDisplay(doctor);

            return `
            <div class="doctor-card">
                <div class="doctor-image">
                    <img src="${escapeHtml(resolveAssetPath(doctor.profile_photo, 'images/default-doctor.svg'))}" 
                         ${resolveLegacyAssetPath(doctor.profile_photo) ? `data-legacy-src="${escapeHtml(resolveLegacyAssetPath(doctor.profile_photo))}"` : ''}
                         onerror="handleAssetImageError(this, 'images/default-doctor.svg')"
                         alt="${escapeHtml(doctor.full_name)}" 
                         loading="lazy">
                    ${doctor.is_featured ? '<span class="doctor-badge">Featured</span>' : ''}
                </div>
                <div class="doctor-info">
                    <h3>${escapeHtml(doctor.full_name)}</h3>
                    <p class="doctor-specialty">${escapeHtml(doctor.specialty)}</p>
                    <div class="doctor-meta">
                        <span class="doctor-rating ${rating.hasReviews ? '' : 'is-new'}" title="${escapeHtml(rating.meta)}">
                            <i class="fas fa-star" aria-hidden="true"></i>
                            ${escapeHtml(rating.value)}
                        </span>
                        <span class="doctor-experience">
                            ${escapeHtml(doctor.experience_years)} years exp
                        </span>
                    </div>
                    <div class="doctor-footer">
                        <span class="doctor-fee">
                            Fee: <span>৳${escapeHtml(doctor.consultation_fee)}</span>
                        </span>
                        <a href="${resolvePagePath('doctor/doctor-profile.html?id=' + encodeURIComponent(doctor.id))}" class="btn btn-primary btn-sm" aria-label="Book appointment with ${escapeHtml(doctor.full_name)}">
                            Book Now
                        </a>
                    </div>
                </div>
            </div>
        `;
        }).join('');
        
    } catch (error) {
        console.error('Failed to load featured doctors:', error);
        container.innerHTML = '<p class="text-center">Failed to load doctors</p>';
    }
}

// Load Doctor List
async function loadDoctorList(params = {}) {
    const container = document.getElementById('doctorsList');
    if (!container) return;
    
    showLoading();
    
    try {
        const result = await API.doctors.list(params);
        
        if (!result.items || result.items.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <h3>No doctors found</h3>
                    <p>Try adjusting your search criteria</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = result.items.map(doctor => {
            const rating = getDoctorRatingDisplay(doctor);

            return `
            <div class="doctor-list-item">
                <div class="doctor-list-image">
                    <img src="${escapeHtml(resolveAssetPath(doctor.profile_photo, 'images/default-doctor.svg'))}" 
                         ${resolveLegacyAssetPath(doctor.profile_photo) ? `data-legacy-src="${escapeHtml(resolveLegacyAssetPath(doctor.profile_photo))}"` : ''}
                         onerror="handleAssetImageError(this, 'images/default-doctor.svg')"
                         alt="${escapeHtml(doctor.full_name)}" 
                         loading="lazy">
                </div>
                <div class="doctor-list-info">
                    <h3>${escapeHtml(doctor.full_name)}</h3>
                    <p class="doctor-list-specialty">${escapeHtml(doctor.specialty)}</p>
                    <p class="doctor-list-qualification">${escapeHtml(doctor.qualification)}</p>
                    <div class="doctor-list-meta">
                        <span><i class="fas fa-star"></i> ${escapeHtml(rating.value)}${rating.hasReviews ? ` (${escapeHtml(rating.meta)})` : ''}</span>
                        <span><i class="fas fa-briefcase"></i> ${escapeHtml(doctor.experience_years)} years</span>
                        <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(doctor.clinic_name || 'Dhaka')}</span>
                    </div>
                </div>
                <div class="doctor-list-actions">
                    <span class="doctor-list-fee">৳${escapeHtml(doctor.consultation_fee)}</span>
                    <a href="${resolvePagePath('doctor/doctor-profile.html?id=' + encodeURIComponent(doctor.id))}" class="btn btn-primary">
                        Book Appointment
                    </a>
                </div>
            </div>
        `;
        }).join('');
        
        // Update pagination
        updatePagination(result, params);
        
    } catch (error) {
        console.error('Failed to load doctors:', error);
        container.innerHTML = '<p class="text-center">Failed to load doctors</p>';
    } finally {
        hideLoading();
    }
}

// Update Pagination
function updatePagination(result, params) {
    const pagination = document.getElementById('pagination');
    if (!pagination) return;
    
    const pag = result.pagination || result;
    
    if (!pag.total_pages || pag.total_pages <= 1) {
        pagination.innerHTML = '';
        return;
    }
    
    let html = '';
    
    // Previous button
    if (pag.current_page > 1) {
        html += `<button class="btn btn-outline" onclick="loadDoctorList({...${JSON.stringify(params)}, page: ${pag.current_page - 1}})">Previous</button>`;
    }
    
    // Page numbers
    html += '<div class="page-numbers">';
    for (let i = 1; i <= pag.total_pages; i++) {
        if (i === pag.current_page) {
            html += `<span class="page-number active">${i}</span>`;
        } else if (i === 1 || i === pag.total_pages || (i >= pag.current_page - 1 && i <= pag.current_page + 1)) {
            html += `<button class="page-number" onclick="loadDoctorList({...${JSON.stringify(params)}, page: ${i}})">${i}</button>`;
        } else if (i === pag.current_page - 2 || i === pag.current_page + 2) {
            html += '<span class="page-dots">...</span>';
        }
    }
    html += '</div>';
    
    // Next button
    if (pag.current_page < pag.total_pages) {
        html += `<button class="btn btn-outline" onclick="loadDoctorList({...${JSON.stringify(params)}, page: ${pag.current_page + 1}})">Next</button>`;
    }
    
    pagination.innerHTML = html;
}

// Load Dashboard Stats
async function loadDashboardStats() {
    const container = document.getElementById('dashboardStats');
    if (!container) return;
    
    try {
        const stats = await API.appointments.statistics();
        
        container.innerHTML = `
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <p class="stat-card-title">Total Appointments</p>
                        <p class="stat-card-value">${stats.total_appointments || 0}</p>
                    </div>
                    <div class="stat-card-icon primary">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <p class="stat-card-title">Completed</p>
                        <p class="stat-card-value">${stats.completed || 0}</p>
                    </div>
                    <div class="stat-card-icon success">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <p class="stat-card-title">Pending</p>
                        <p class="stat-card-value">${stats.pending || 0}</p>
                    </div>
                    <div class="stat-card-icon warning">
                        <i class="fas fa-clock"></i>
                    </div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-header">
                    <div>
                        <p class="stat-card-title">Today's Appointments</p>
                        <p class="stat-card-value">${stats.today_count || 0}</p>
                    </div>
                    <div class="stat-card-icon info">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
            </div>
        `;
    } catch (error) {
        console.error('Failed to load stats:', error);
    }
}

// Load Upcoming Appointments
async function loadUpcomingAppointments() {
    const container = document.getElementById('upcomingAppointments');
    if (!container) return;
    
    try {
        const appointments = await API.appointments.upcoming();
        
        if (!appointments || appointments.length === 0) {
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">
                        <i class="fas fa-calendar"></i>
                    </div>
                    <h3>No upcoming appointments</h3>
                    <p>Book an appointment with a doctor</p>
                    <a href="${resolvePagePath('pages/doctors.html')}" class="btn btn-primary">Find Doctors</a>
                </div>
            `;
            return;
        }
        
        container.innerHTML = `
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Doctor</th>
                            <th>Date</th>
                            <th>Token</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${appointments.map(apt => `
                            <tr>
                                <td>
                                    <div class="table-user">
                                        <span class="table-user-name">${escapeHtml(apt.doctor_name)}</span>
                                        <span class="table-user-info">${escapeHtml(apt.specialty)}</span>
                                    </div>
                                </td>
                                <td>
                                    <div class="table-date">
                                        <span>${formatDate(apt.appointment_date)}</span>
                                        <span>${formatTime(apt.estimated_time)}</span>
                                    </div>
                                </td>
                                <td><span class="token-badge">#${escapeHtml(apt.token_number)}</span></td>
                                <td><span class="status-badge ${escapeHtml(apt.status)}">${escapeHtml(apt.status)}</span></td>
                                <td>
                                    <a href="${resolvePagePath('patient/appointment.html?id=' + encodeURIComponent(apt.id) + '&v=20260403-1')}" class="btn btn-sm btn-outline">View</a>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    } catch (error) {
        console.error('Failed to load appointments:', error);
    }
}

// Initialize page
function initPage() {
    ensureFavicon();
    ensureThemeToggle();
    initMobileNav();
    makeIconsDecorative();
    loadHeroStats();
    initTestimonials();
    
    // Initialize tooltips
    const tooltips = document.querySelectorAll('[data-tooltip]');
    tooltips.forEach(el => {
        el.addEventListener('mouseenter', (e) => {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = e.target.dataset.tooltip;
            document.body.appendChild(tooltip);
            
            const rect = e.target.getBoundingClientRect();
            tooltip.style.left = rect.left + rect.width / 2 - tooltip.offsetWidth / 2 + 'px';
            tooltip.style.top = rect.top - tooltip.offsetHeight - 8 + 'px';
        });
        
        el.addEventListener('mouseleave', () => {
            document.querySelector('.tooltip')?.remove();
        });
    });
}

// Run initialization
document.addEventListener('DOMContentLoaded', initPage);
