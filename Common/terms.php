<?php
session_start();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Terms & Conditions | Restaurant Signup</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="admin.css" rel="stylesheet">
  <style>
    body {
      background: #fff8f2;
      font-family: 'Poppins', sans-serif;
    }
    .terms-container {
      max-width: 900px;
      background: #ffffff;
      margin: 60px auto;
      padding: 40px;
      border-radius: 15px;
      box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    }
    .terms-header {
      text-align: center;
      margin-bottom: 40px;
    }
    .terms-header h2 {
      color: #d35400;
      font-weight: 700;
    }
    .terms-header p {
      color: #6c757d;
      font-size: 0.95rem;
    }
    h5 {
      color: #333;
      margin-top: 25px;
      font-weight: 600;
    }
    ul {
      margin-top: 10px;
    }
    .btn-back {
      background-color: #d35400;
      border: none;
      color: #fff;
      padding: 10px 20px;
      border-radius: 10px;
      transition: background-color 0.3s ease;
    }
    .btn-back:hover {
      background-color: #b94600;
    }
  </style>
</head>
<body>

  <div class="container terms-container">
    <div class="terms-header">
      <!-- <img src="../Assets/restaurant-logo.png" alt="Logo" height="70" class="mb-3"> -->
      <h2>Terms & Conditions</h2>
      <p>Please review these terms carefully before creating your restaurant account.</p>
    </div>

    <div class="terms-content">
      <h5>1. Introduction</h5>
      <p>
        Welcome to our Restaurant Management System (“the Service”). By signing up, you agree to comply with and be bound by the following terms and conditions. 
        Please read them carefully. These terms govern your use of the Service provided by our company.
      </p>

      <h5>2. Account Registration</h5>
      <ul>
        <li>You must provide accurate, current, and complete information during registration.</li>
        <li>You are responsible for maintaining the confidentiality of your account credentials.</li>
        <li>Any misuse or unauthorized access to your account must be reported immediately.</li>
      </ul>

      <h5>3. Service Usage</h5>
      <ul>
        <li>You may use the Service only for legitimate restaurant operations.</li>
        <li>You must not use the platform for any illegal, fraudulent, or unauthorized purpose.</li>
        <li>We reserve the right to suspend accounts that violate these terms.</li>
      </ul>

      <h5>4. Data Privacy</h5>
      <p>
        We value your privacy. All restaurant and user information provided will be handled securely and used only for purposes related to this Service.
        Personal and operational data will never be shared with third parties without consent, except as required by law.
      </p>

      <h5>5. Subscription and Payments</h5>
      <p>
        Some features may require a paid subscription. Payment terms and renewal policies will be clearly displayed within the platform.
      </p>

      <h5>6. Termination</h5>
      <p>
        We may terminate or suspend your access to the Service if you violate these terms or engage in conduct that harms the platform or other users.
      </p>

      <h5>7. Limitation of Liability</h5>
      <p>
        We are not liable for any direct, indirect, or incidental damages resulting from your use or inability to use the Service, including but not limited to loss of data or revenue.
      </p>

      <h5>8. Modifications to Terms</h5>
      <p>
        We may update or modify these terms at any time. Continued use of the Service after changes constitutes your acceptance of the new terms.
      </p>

      <h5>9. Contact Information</h5>
      <p>
        If you have any questions about these Terms and Conditions, please contact us through the official channels provided in the application.
      </p>

      <div class="text-center mt-4">
        <?php 
          $backLink = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'signup.php';
        ?>
        <a href="<?= htmlspecialchars($backLink) ?>" class="btn btn-back">
          <i class="bi bi-arrow-left"></i> Back
        </a>
      </div>

    </div>
  </div>

  <?php include('footer.php'); ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
