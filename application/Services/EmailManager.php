<?php
namespace Luminance\Services;

use Luminance\Core\Master;
use Luminance\Errors\ConfigurationError;
use Luminance\Errors\SystemError;
use Luminance\Errors\UserError;
use Luminance\Errors\AuthError;
use Luminance\Errors\InternalError;
use Luminance\Errors\ForbiddenError;
use Luminance\Errors\UnauthorizedError;
use Luminance\Entities\Email;

class EmailManager extends Service {

    protected static $useRepositories = [
        'emails' => 'EmailRepository',
    ];

    protected static $useServices = [
        'db' => 'DB',
    ];

    public function reduceEmail($address) {
        $parts = explode('@', $address);
        if (count($parts) != 2) {
            throw new InternalError("Passed invalid e-mail address to reduceEmail()");
        }
        list($user, $domain) = $parts;

        if (strpos($user, '+')) {
            # Handle extension addresses
            $user = explode('+', $user, 2)[0];
        }

        $domainParts = array_reverse(explode('.', $domain));
        foreach ($domainParts as $idx => $part) {
            $domain = $part;
            if (strlen($part) >= 5 && $idx >= 1) {
                break;
            }
        }
        return "{$user}@{$domain}";
    }

    public function checkEmailAvailable($address) {
        // reduce fannies around with the domain. :(
        //$address = $this->reduceEmail($address);
        $email = $this->emails->get_by_address($address);
        if ($email) {
            return false;
        }

        $stmt = $this->db->raw_query("SELECT ID FROM users_main WHERE Email = ?", [$address]);
        $user_legacy = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($user_legacy) {
            return false;
        }
        return true;
    }

    public function newEmail($userID, $address) {
        $email = new Email();
        $email->UserID = $userID;
        $email->Address = $address;
        $email->Reduced = $this->reduceEmail($address);
        $email->Flags = 0;
        $this->emails->save($email);

        /*
         * This piece of code will update the time of their last email change
         * to the current time *not* the current change.
         */
        if (!empty($this->master->request->user->ID)) {
            $this->db->raw_query(
                "UPDATE users_history_emails
                    SET Time=:time
                  WHERE UserID=:userid
                    AND Time='0000-00-00 00:00:00'",
                [':time' => sqltime(), ':userid' => $userID]);

            $this->db->raw_query(
                "INSERT INTO users_history_emails
                    (UserID, Email, Time, IP, ChangedbyID) VALUES
                    (:userid, :address, '0000-00-00 00:00:00', :changerip, :changerid)",
                    [':userid'    => $userID,
                     ':address'   => $address,
                     ':changerip' => $this->master->request->IP,
                     ':changerid' => $this->master->request->user->ID]
            );
        }

        return $email;
    }


}
