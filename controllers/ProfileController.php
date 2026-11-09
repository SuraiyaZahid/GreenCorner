<?php
/**
 * GreenCorner — Profile Controller
 *
 * Serves the Profile page (P-13): the account's own details, the form that
 * changes the account's full name, and the form that changes the account's
 * password.
 *
 * Implements:
 *   FR-5.5  the User may change their own full name; the role, the email
 *           address and the account status may never be changed through
 *           this interface, and any such field supplied by the client is
 *           discarded
 *   FR-5.6  the User may change their own password after providing the
 *           current password; the new password is subject to FR-1.3; on
 *           success the session identifier is rotated and the other
 *           sessions for the account are invalidated
 *   FR-1.3  the password rules
 *   FR-1.9  the forced-change flag is cleared when the password is changed
 *   FR-7.4  an Admin is refused the page, because the `shopping` capability
 *           is held by Users only
 *
 * The account the page acts on is always the signed-in account. **No
 * identifier is ever read from the request**, so there is no object to
 * forge and no way to reach another account (RBAC-9).
 */

require_once __DIR__ . '/../helpers/functions.php';
require_once __DIR__ . '/../helpers/csrf.php';
require_once __DIR__ . '/../helpers/authorization.php';
require_once __DIR__ . '/../helpers/session_handler.php';
require_once __DIR__ . '/../models/User.php';

class ProfileController
{
    /**
     * The Profile page (P-13).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function show(array $parameters = array()): void
    {
        // FR-7.4 / the RTM page row for P-13: the page is for Users only.
        // An Admin receives HTTP 403.
        require_capability('shopping.profile');

        $profileUser = current_user();

        // A refused submission is re-rendered in place, with the error shown
        // inline, so there is no error stored in the session. The only value
        // carried over is the name the customer typed before a refused
        // attempt, which the CSRF handler preserves.
        $profileErrors = array();

        // The name the customer typed before a refused attempt is kept, so
        // they do not have to type it again.
        $profileName = (string) ($_SESSION['profile_name'] ?? $profileUser['full_name']);
        unset($_SESSION['profile_name']);

        require __DIR__ . '/../views/profile/index.php';
    }

    /**
     * Change the account's full name (FR-5.5).
     *
     * An empty or over-length name is rejected and the page is re-rendered
     * with the message inline, as FR-5.5 acceptance criterion 3 requires.
     * The rejection is answered with HTTP 422, the status the traceability
     * matrix uses for a rejected submission.
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function updateName(array $parameters = array()): void
    {
        require_capability('shopping.profile');

        csrf_require(false, 'profile_name');

        $user = current_user();
        $userId = (int) $user['id'];

        // FR-5.5: only the full name is read from the request. A
        // `role_id`, an `is_active` or an `email` field supplied by the
        // client is simply never looked at, and `User::updateName()`
        // writes the `full_name` column alone.
        $fullName = request_string('full_name');

        $errors = array();

        if ($fullName === '') {
            $errors['full_name'] = 'Please enter your name.';
        } elseif (mb_strlen($fullName) > User::MAX_NAME_LENGTH) {
            $errors['full_name'] = 'Your name may be at most ' . User::MAX_NAME_LENGTH . ' characters long.';
        }

        if ($errors !== array()) {
            $this->renderProfile($errors, $fullName, 'Please correct the errors below and try again.');
            return;
        }

        User::updateName($userId, $fullName);

        // The cached account still carries the old name, so it is dropped.
        forget_cached_user();

        unset($_SESSION['profile_name']);

        set_flash('success', 'Your name has been updated.');
        redirect('/profile');
    }

    /**
     * Change the account's password (FR-5.6).
     *
     * @param array<string, mixed> $parameters The URL parameters (unused).
     */
    public function changePassword(array $parameters = array()): void
    {
        require_capability('shopping.profile');

        csrf_require(false, 'profile_password');

        $user = current_user();
        $userId = (int) $user['id'];

        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['new_password_confirmation'] ?? '');

        $errors = array();

        // FR-5.6: the current password must be provided. The password is
        // checked against the stored hash; the plaintext is never stored.
        if ($currentPassword === '') {
            $errors['current_password'] = 'Please enter your current password.';
        } elseif (!User::verifyPassword($currentPassword, (string) $user['password_hash'])) {
            $errors['current_password'] = 'Your current password is not correct.';
        }

        // FR-1.3: the new password is subject to the same rules as at
        // registration.
        if ($newPassword === '') {
            $errors['new_password'] = 'Please choose a new password.';
        } elseif (strlen($newPassword) < User::MIN_PASSWORD_LENGTH) {
            $errors['new_password'] = 'Your new password must be at least '
                . User::MIN_PASSWORD_LENGTH . ' characters long.';
        }

        if ($confirmPassword === '') {
            $errors['new_password_confirmation'] = 'Please confirm your new password.';
        } elseif ($newPassword !== $confirmPassword) {
            $errors['new_password_confirmation'] = 'The two passwords do not match.';
        }

        if ($errors !== array()) {
            // No password is ever preserved in the session or written back
            // into the response (SEC-13).
            $this->renderProfile($errors, (string) $user['full_name'], 'Please correct the errors below and try again.');
            return;
        }

        // FR-5.6 / FR-1.9: the new password is stored as a hash, and the
        // forced-change flag is cleared.
        User::setPassword($userId, $newPassword, false);

        forget_cached_user();

        // FR-5.6: the session identifier is rotated, and the other sessions
        // for this account are invalidated.
        session_regenerate_id(true);
        invalidate_other_sessions($userId);

        // The CSRF token is rotated with the session identifier, so a token
        // captured before the change is no longer valid.
        csrf_rotate();

        unset($_SESSION['profile_errors']);
        unset($_SESSION['profile_name']);

        set_flash('success', 'Your password has been changed.');
        redirect('/profile');
    }

    /**
     * Re-render the Profile page with a rejected submission.
     *
     * The page is rendered with HTTP 422 and the field errors are shown
     * inline, next to the field they belong to (FR-5.5 acceptance criterion
     * 3). No error is stored in the session, so a message can never be shown
     * against an unrelated later request.
     *
     * @param array<string, string> $errors    The field errors.
     * @param string                $name      The name to show in the form.
     * @param string                $flashText The summary message.
     */
    private function renderProfile(array $errors, string $name, string $flashText): void
    {
        http_response_code(422);

        set_flash('error', $flashText);

        $profileUser = current_user();
        $profileErrors = $errors;
        $profileName = $name;

        require __DIR__ . '/../views/profile/index.php';
    }
}
