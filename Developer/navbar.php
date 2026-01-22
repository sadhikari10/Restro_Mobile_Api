<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['developer_logged_in'])) header('Location: login.php');

$developerName = htmlspecialchars($_SESSION['developer_name']);
require '../Common/connection.php';
// require '../Common/nepali_date.php';

?>
<nav class="navbar">
    <div class="logo">Developer Panel</div>
    <div class="hamburger" onclick="toggleMenu()">
        <span></span>
        <span></span>
        <span></span>
    </div>
    <ul id="nav-menu">
        <li><a href="dashboard.php">Dashboard</a></li>
        <li><a href="payment_history.php">Payment History</a></li>
        <li><a href="trial_accounts.php">Trial Accounts</a></li>
        <li><a href="running_restaurants.php">Running Restaurants</a></li>
        <li><a href="expired_accounts.php">Expired Accounts</a></li>
        <li><a href="profile.php">Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
    <div style="display:flex; align-items:center; gap:10px;">
        <div style="position: relative;">
            <a href="#" id="notifBell" onclick="openNotificationModal()">🔔
                <span id="notifCount" style="position:absolute; top:-5px; right:-10px; background:red; color:white; border-radius:50%; padding:2px 6px; font-size:12px;">0</span>
            </a>
        </div>
        <div class="developer-info">👋 Welcome, <?php echo $developerName; ?></div>
    </div>
</nav>

<link rel="stylesheet" href="developer.css">

<!-- Notification Modal -->
<div id="notifModal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%);
background:#fff; width:450px; max-height:500px; overflow-y:auto; box-shadow:0 0 10px rgba(0,0,0,0.3);
border-radius:8px; z-index:10000; padding:20px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <h5>Notifications</h5>
        <button onclick="closeNotificationModal()" style="background:none; border:none; font-size:18px; cursor:pointer;">&times;</button>
    </div>
    <div>
        <h6>New Notifications</h6>
        <ul id="notifNotSeen" style="list-style:none; padding:0; margin:0;"></ul>
    </div>
    <div style="margin-top:15px;">
        <h6>Seen Notifications</h6>
        <ul id="notifSeen" style="list-style:none; padding:0; margin:0;"></ul>
        <button id="loadMoreSeen" style="display:none; margin-top:5px; padding:5px 10px;">Load More Seen</button>
    </div>
</div>

<div id="notifOverlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%;
background:rgba(0,0,0,0.5); z-index:9999;" onclick="closeNotificationModal()"></div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
function toggleMenu() {
    const menu = document.getElementById('nav-menu');
    menu.classList.toggle('show');
}

let notSeenNotifications = [];
let seenNotifications = [];
let seenLimit = 25;
let modalOpen = false;

function openNotificationModal() {
    modalOpen = true;
    $('#notifModal, #notifOverlay').show();
    $('#notifCount').text('0');

    // Load modal
    renderModal();
}

function closeNotificationModal() {
    modalOpen = false;

    // Mark notifications as seen (affects counter and DB)
    $.ajax({
        url: 'mark_notifications_seen.php',
        type: 'POST',
        success: function() {
            // Move all notSeen to seen segment
            seenNotifications = [...notSeenNotifications, ...seenNotifications];
            if(seenNotifications.length > 100) seenNotifications = seenNotifications.slice(0,100); // limit
            notSeenNotifications = [];
            $('#notifModal, #notifOverlay').hide();
            renderModal();
            $('#notifCount').text('0'); // Ensure counter is 0 after marking
        }
    });
}

// Fetch notifications every 5 seconds
function fetchNotifications() {
    $.ajax({
        url:'generate_and_fetch_notifications.php',
        dataType:'json',
        success:function(response){
            // Update bell counter only for unseen, and only if modal is not open
            let unseenCount = response.filter(n => n.seen==0).length;
            if (!modalOpen) {
                $('#notifCount').text(unseenCount);
            }

            // Separate seen and not seen
            response.forEach(function(n){
                if(n.seen==0){
                    if(!notSeenNotifications.some(x=>x.id==n.id)) notSeenNotifications.push(n);
                } else {
                    if(!seenNotifications.some(x=>x.id==n.id)) seenNotifications.push(n);
                }
            });

            // Keep seenNotifications max 100
            if(seenNotifications.length>100) seenNotifications = seenNotifications.slice(0,100);

            renderModal();
        }
    });
}

function renderModal(){
    // Render New Notifications
    let listNotSeen = $('#notifNotSeen');
    listNotSeen.empty();
    notSeenNotifications.forEach(function(n){
        listNotSeen.append('<li style="padding:5px 0; border-bottom:1px solid #eee;">'+n.message+'</li>');
    });

    // Render Seen up to seenLimit
    let listSeen = $('#notifSeen');
    listSeen.empty();
    let seenToShow = seenNotifications.slice(0, seenLimit);
    seenToShow.forEach(function(n){
        listSeen.append('<li style="padding:5px 0; border-bottom:1px solid #eee; color:gray;">'+n.message+'</li>');
    });

    // Show or hide Load More button
    if(seenNotifications.length > seenLimit){
        $('#loadMoreSeen').show();
    } else {
        $('#loadMoreSeen').hide();
    }
}

$('#loadMoreSeen').click(function(){
    seenLimit = 100;
    renderModal();
});

// Poll every 5 seconds
setInterval(fetchNotifications,5000);
fetchNotifications();
</script>