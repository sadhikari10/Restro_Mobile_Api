<!-- SuperAdmin/navbar.php -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="index.php">
      <i class="bi bi-building"></i> <?= htmlspecialchars($chain_name ?? 'Restaurant Software') ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link text-white" href="index.php">
            <i class="bi bi-house"></i> Dashboard
          </a>
        </li>
      </ul>
      <div class="d-flex align-items-center ms-3">
        <span class="text-white me-3">
          <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['username']) ?> (Owner)
        </span>
        <a href="../Common/logout.php" class="btn btn-outline-light btn-sm">
          <i class="bi bi-box-arrow-right"></i> Logout
        </a>
      </div>
    </div>
  </div>
</nav>