<?php

class FriendController {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }

    public function index() {
        $userId = $_SESSION['user_id'];
        
        $friends = [];
        $pendingReceived = [];
        $pendingSent = [];
        $suggestions = [];
        
        try {
            $stmt = $this->db->prepare("
                SELECT u.*, f.created_at as friend_since
                FROM friendships f
                JOIN users u ON (f.friend_id = u.id)
                WHERE f.user_id = ? AND f.status = 'accepted'
                ORDER BY u.full_name
            ");
            $stmt->execute([$userId]);
            $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->db->prepare("
                SELECT u.*, f.id as request_id, f.created_at
                FROM friendships f
                JOIN users u ON f.user_id = u.id
                WHERE f.friend_id = ? AND f.status = 'pending' AND f.action_user_id != ?
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$userId, $userId]);
            $pendingReceived = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->db->prepare("
                SELECT u.*, f.created_at
                FROM friendships f
                JOIN users u ON f.friend_id = u.id
                WHERE f.user_id = ? AND f.status = 'pending' AND f.action_user_id = ?
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$userId, $userId]);
            $pendingSent = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->db->prepare("
                SELECT u.*
                FROM users u
                WHERE u.id != ?
                AND u.id NOT IN (
                    SELECT friend_id FROM friendships WHERE user_id = ?
                    UNION
                    SELECT user_id FROM friendships WHERE friend_id = ?
                )
                ORDER BY RAND()
                LIMIT 10
            ");
            $stmt->execute([$userId, $userId, $userId]);
            $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
        }
        
        $pageTitle = 'Friends | Nexo';
        require_once __DIR__ . '/../views/friends/index.php';
    }

    public function sendRequest() {
        header('Content-Type: application/json');
        
        $friendId = $_POST['friend_id'] ?? null;
        $userId = $_SESSION['user_id'];
        
        if (!$friendId || $friendId == $userId) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO friendships (user_id, friend_id, status, action_user_id)
                VALUES (?, ?, 'pending', ?), (?, ?, 'pending', ?)
            ");
            $stmt->execute([$userId, $friendId, $userId, $friendId, $userId, $userId]);
            
            try {
                $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                NotificationController::create(
                    $friendId,
                    'friend_request',
                    $userId,
                    $userId,
                    $user['full_name'] . ' sent you a friend request'
                );
            } catch (Exception $e) {
            }
            
            echo json_encode(['success' => true, 'message' => 'Friend request sent']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Request already sent or failed']);
        }
        exit;
    }

    public function acceptRequest() {
        header('Content-Type: application/json');
        
        $friendId = $_POST['friend_id'] ?? null;
        $userId = $_SESSION['user_id'];
        
        if (!$friendId) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE friendships 
                SET status = 'accepted', updated_at = NOW()
                WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
                AND status = 'pending'
            ");
            $stmt->execute([$userId, $friendId, $friendId, $userId]);
            
            try {
                $stmt = $this->db->prepare("SELECT full_name FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                NotificationController::create(
                    $friendId,
                    'friend_accept',
                    $userId,
                    $userId,
                    $user['full_name'] . ' accepted your friend request'
                );
            } catch (Exception $e) {
            }
            
            echo json_encode(['success' => true, 'message' => 'Friend request accepted']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Could not accept request']);
        }
        exit;
    }

    public function declineRequest() {
        header('Content-Type: application/json');
        
        $friendId = $_POST['friend_id'] ?? null;
        $userId = $_SESSION['user_id'];
        
        if (!$friendId) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        try {
            $stmt = $this->db->prepare("
                DELETE FROM friendships 
                WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
                AND status = 'pending'
            ");
            $stmt->execute([$userId, $friendId, $friendId, $userId]);
            
            echo json_encode(['success' => true, 'message' => 'Request declined']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Could not decline request']);
        }
        exit;
    }

    public function unfriend() {
        header('Content-Type: application/json');
        
        $friendId = $_POST['friend_id'] ?? null;
        $userId = $_SESSION['user_id'];
        
        if (!$friendId) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        
        try {
            $stmt = $this->db->prepare("
                DELETE FROM friendships 
                WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
            ");
            $stmt->execute([$userId, $friendId, $friendId, $userId]);
            
            echo json_encode(['success' => true, 'message' => 'Unfriended successfully']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Could not unfriend']);
        }
        exit;
    }

    public function getStatus() {
        header('Content-Type: application/json');
        
        $friendId = $_GET['friend_id'] ?? null;
        $userId = $_SESSION['user_id'];
        
        if (!$friendId) {
            echo json_encode(['success' => false, 'status' => 'none']);
            exit;
        }
        
        if ($friendId == $userId) {
            echo json_encode(['success' => true, 'status' => 'self']);
            exit;
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT status, action_user_id 
                FROM friendships 
                WHERE user_id = ? AND friend_id = ?
            ");
            $stmt->execute([$userId, $friendId]);
            $friendship = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$friendship) {
                echo json_encode(['success' => true, 'status' => 'none']);
            } else {
                $status = $friendship['status'];
                if ($status === 'pending') {
                    $isSender = ($friendship['action_user_id'] == $userId);
                    echo json_encode([
                        'success' => true, 
                        'status' => $isSender ? 'pending_sent' : 'pending_received'
                    ]);
                } else {
                    echo json_encode(['success' => true, 'status' => $status]);
                }
            }
        } catch (PDOException $e) {
            echo json_encode(['success' => true, 'status' => 'none']);
        }
        exit;
    }
}
