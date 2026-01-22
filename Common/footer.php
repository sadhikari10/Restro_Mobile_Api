<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Footer</title>
  <style>
    /* Reset */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    html, body {
      height: 100%;
      font-family: Arial, sans-serif;
    }

    body {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      background-color: #f9f9f9;
    }

    footer {
      background-color: #000; /* black background */
      color: #fff; /* white text */
      text-align: center;
      padding: 20px 10px;
      font-size: 14px;
      width: 100%;
      margin-top: auto; /* pushes footer down */
    }

    footer a {
      color: #d35400; /* warm restaurant accent */
      text-decoration: none;
      font-weight: bold;
      margin: 0 5px;
    }

    footer a:hover {
      text-decoration: underline;
    }

    @media (max-width: 600px) {
      footer {
        font-size: 13px;
        padding: 15px 8px;
      }
    }

    @media (max-width: 400px) {
      footer {
        font-size: 12px;
      }
    }
  </style>
</head>
<body>

  <footer>
    <p>Restaurant Management System &copy; <?= date('Y') ?>.</p>
    <p>
      <a href="../Common/terms.php" target="_blank">Terms & Conditions</a> | 
      <a href="../Common/privacy_policy.php" target="_blank">Privacy Policy</a> | 
      <a href="../Common/suggestions.php">Suggestions</a> | 
      <a href="../Common/issues_center.php">Issue Center</a> |
      Support: +977-9840032900
    </p>
    <p>Developed by <a href="https://www.strcomputer.com.np" target="_blank">STR Computer</a></p>
</footer>


</body>
</html>
