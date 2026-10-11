<?php
session_start();
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Digital Healthcare & Hospital Management';
require __DIR__ . '/includes/header.php';
?>
<section class="portal-hero mb-5">
    <div class="hero-copy">
        <p class="eyebrow">Connected care, thoughtfully managed</p>
        <h1>Digital Healthcare &amp; Hospital Management</h1>
        <p class="hero-lead">Secure management of patient identity, appointments, medical records and consent.</p>
        <div class="d-flex flex-wrap gap-2"><a class="btn btn-primary btn-lg" href="sso_login.php">Sign In <span aria-hidden="true">&rarr;</span></a><a class="btn btn-outline-dark btn-lg" href="create_health_identity.php">Create Health Identity</a></div>
    </div>
    <div class="hero-aside" aria-label="Hospital portal overview"><span class="hero-plus">+</span><strong>MediCore</strong><span>One connected view of care</span><div class="hero-line"></div><small>Identity &nbsp;|&nbsp; Care &nbsp;|&nbsp; Trust</small></div>
</section>
<section id="services" class="mb-5"><div class="section-heading"><p class="eyebrow">Portal services</p><h2>Everything your care team needs</h2></div><div class="row g-3">
<?php foreach ([['Health Identity', 'A single, secure patient identity'], ['Identity Verification', 'Reliable verification for trusted access'], ['Appointments', 'Coordinate visits across departments'], ['Medical Records', 'Organize clinical information securely'], ['Prescriptions', 'Keep treatment plans clear and accessible'], ['Consent Management', 'Give patients control of their information'], ['Audit & Security', 'Accountability built into every action']] as $feature): ?>
    <div class="col-sm-6 col-lg-4"><div class="service-tile h-100"><span class="service-number">0<?= array_search($feature, [['Health Identity', 'A single, secure patient identity'], ['Identity Verification', 'Reliable verification for trusted access'], ['Appointments', 'Coordinate visits across departments'], ['Medical Records', 'Organize clinical information securely'], ['Prescriptions', 'Keep treatment plans clear and accessible'], ['Consent Management', 'Give patients control of their information'], ['Audit & Security', 'Accountability built into every action']]) + 1 ?></span><h2 class="h5 mt-4 mb-2"><?= e($feature[0]) ?></h2><p class="text-muted mb-0"><?= e($feature[1]) ?></p></div></div>
<?php endforeach; ?>
</div></section>
<section id="about" class="about-strip"><p class="eyebrow">Designed for every team</p><h2 class="h4">One portal. Five clear roles.</h2><p class="mb-0 text-muted">Patients, Front Desk Officers, doctors, consent managers and administrators each get the tools and access appropriate to their responsibilities.</p></section>
<?php require __DIR__ . '/includes/footer.php'; ?>