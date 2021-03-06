<?php
declare (strict_types = 1);
namespace RCSE\Core;

class User
{
    /** @var Configurator */
    private $config;

    /** @var Database */
    private $database;

    /** @var Logger */
    private $logger;

    /** @var Utils */
    private $utils;

    /** @var File */
    private $file;

    public function __construct(Logger $logger, Configurator $config)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->utils = new Utils();
        $this->file = new File();
        $this->database = new Database($this->logger, $this->config);
    }

    public function userRegister(string $login, string $password, string $email, string $sex, string $birthdate, string $origin)
    {
        if ($this->database->databaseCheckData('accounts', 'by_login', $login)) {
            $message = "Failed to register account: login {$login} is already claimed!";
            $this->logger->log($this->logger::ERROR, $message, get_class($this));
            return $message;
        }
        if ($this->database->databaseCheckData('accounts', 'by_email', $email)) {
            $message = "Failed to register account: email {$email} is already claimed!";
            $this->logger->log($this->logger::ERROR, $message, get_class($this));
            return $message;
        }
        if ($birthdate !== null) {
            $birthdate_unf = new \DateTime($birthdate);
            $birthdate = $birthdate_unf->format('Y-m-d');
        }
        $regdate = date('Y-m-d');
        $passhash = password_hash($password, PASSWORD_DEFAULT);
        $settings = [
            "avatar" => "default",
            "group" => "user"
        ];

        $params = [
            ":login" => $login,
            ":password" => $passhash,
            ":email" => $email,
            ":sex" => $sex,
            ":birthdate" => $birthdate,
            ":origin" => $origin,
            ":regdate" => $regdate,
            ":settings" => json_encode($settings)
        ];

        $file_path = "/userdata/{$login}/";
        $file_name = "session.json";

        $this->file->fileOpen("c", $file_path, $file_name);
        $this->file->fileWriteLine("{}");

        try {
            return $this->database->databaseSendData('accounts', 'insert', $params);
        } catch (\Exception $e) {
            $this->logger->log($this->logger::ERROR, $e->getMessage(), get_class($this));
            return $e->getMessage();
        }
    }

    public function userLogin(string $id, string $pass, bool $save_session)
    {
        if (strpos($id, '@') != false && strpos($id, '.') != false) {
            $type = "by_email";
        } else {
            $type = "by_login";
        }

        if ($this->database->databaseCheckData('accounts', $type, $id) === false) {
            $message = "Failed to login: account for {$id} not found!";
            $this->logger->log($this->logger::ERROR, $message, get_class($this));
            return $message;
        }

        try {
            $user_data = $this->database->databaseGetData('accounts', $type, $id);
        } catch (\Exception $e) {
            $this->logger->log($this->logger::ERROR, $e->getMessage(), get_class($this));
            return $e->getMessage();
        }

        if (password_verify($pass, $user_data['password']) === false) {
            $message = "Failed to login: password doesn't match!";
            $this->logger->log($this->logger::NOTICE, $message, get_class($this));
            return $message;
        }

        $this->userSessionCreate($user_data['login'], $save_session);

        return true;
    }

    public function userGetInfo(string $login)
    {
        if ($this->database->databaseCheckData('accounts', 'by_login', $login) === false) {
            $message = "Failed to obtain data: account for {$login} not found!";
            $this->logger->log($this->logger::ERROR, $message, get_class($this));
            return $message;
        }

        try {
            $user_data = $this->database->databaseGetData('accounts', 'by_login', $login);
        } catch (\Exception $e) {
            $this->logger->log($this->logger::ERROR, $e->getMessage(), get_class($this));
            return false;
        }

        return $user_data;
    }

    public function userGetUsergroup(string $login)
    { }

    public function userGroupIsPermitted(string $usergroup, string $permission)
    { }

    public function userVerify(string $login, string $code)
    { }

    public function userPunish(string $login, array $params)
    { }

    public function userEdit(string $login, array $data)
    { }

    public function userSuspend(string $login)
    { }

    public function userRemove(string $login)
    { }

    public function userResetPass(string $login, string $code = null)
    { }

    private function userSessionCreate(string $login, bool $save_session)
    {
        $file_path = "/userdata/{$login}/";
        $file_name = "session.json";
        $date = new \DateTime();
        $current_date = $date->format("H:i d-m-Y");


        $session_id = $this->utils->utilsRandomString(16);

        $this->logger->log($this->logger::INFO, "Creating new session, id: {$session_id}.", get_class($this));

        try {
            $session_file_data = json_decode($this->file->fileRead($file_path, $file_name), true);
        } catch (\Exception $e) {
            $this->logger->log($this->logger::ERROR, $e->getMessage(), get_class($this));
            return false;
        }

        if (!$save_session) $session_exp = $date->add(new \DateInterval('P1D'))->format("H:i d-m-Y");
        else $session_exp = $date->add(new \DateInterval('P5Y'))->format("H:i d-m-Y");

        $session_data = [
            "date_created" => $current_date,
            "date_expires" => $session_exp,
            "ip_created" => $this->utils->utilsGetClientIP(),
            "ips" => [$this->utils->utilsGetClientIP()]
        ];

        $session_file_data[$session_id] = $session_data;

        $this->userSessionUpdate($login, $session_file_data);

        if ($session_exp !== 0) $session_exp = time() + 60 * 60 * 24 * 365 * 5;

        setcookie("session_id", $session_id, $session_exp, '/');
        setcookie("session_login", $login, $session_exp, '/');

        $this->logger->log($this->logger::INFO, "Session created successfully.", get_class($this));

        return true;
    }

    private function userSessionObtainAll(string $login)
    {
        $file_path = "/userdata/{$login}/";
        $file_name = "session.json";

        $this->logger->log($this->logger::INFO, "Obtaining sessions.", get_class($this));

        try {
            $session_file_data = json_decode($this->file->fileRead($file_path, $file_name), true);
        } catch (\Exception $e) {
            $this->logger->log($this->logger::ERROR, $e->getMessage(), get_class($this));
            return false;
        }

        $this->logger->log($this->logger::INFO, "Sessions obtained successfully.", get_class($this));


        return $session_file_data;
    }

    private function userSessionUpdate(string $login, $data)
    {
        $file_path = "/userdata/{$login}/";
        $file_name = "session.json";

        $this->logger->log($this->logger::INFO, "Updating session file.", get_class($this));

        try {
            $this->file->fileWrite($file_path, $file_name, json_encode($data));
        } catch (\Exception $e) {
            $this->logger->log($this->logger::ERROR, $e->getMessage(), get_class($this));
            return false;
        }

        $this->logger->log($this->logger::INFO, "Sessions updated successfully.", get_class($this));

        return true;
    }

    private function userSessionObtain(string $login, string $session_id)
    {
        $this->logger->log($this->logger::INFO, "Obtaining session {$session_id}.", get_class($this));

        $session_file_data = $this->userSessionObtainAll($login)[$session_id];

        $this->logger->log($this->logger::INFO, "Session obtained successfully.", get_class($this));

        return $session_file_data;
    }

    private function userSessionValidate(string $login, string $session_id)
    {
        $current_date = new \DateTime();
        $session_file_data = $this->userSessionObtain($login, $session_id);
        if ($session_file_data['date_expires'] == 0) {
            $date_expires = new \DateTime($session_file_data['date_created']);
        } else {
            $date_expires = new \DateTime($session_file_data['date_expires']);
        }

        $this->logger->log($this->logger::INFO, "Validation user session {$session_id}.", get_class($this));

        if ($date_expires <= $current_date) {
            unset($_COOKIE["session_id"]);
            unset($_COOKIE["session_login"]);
            setcookie("session_id", "", time() - 3600, '/');
            setcookie("session_login", "", time() - 3600, '/');
            $this->logger->log($this->logger::INFO, "Session expired.", get_class($this));
            return false;
        } else {
            $session_file_data["ips"][] = $this->utils->utilsGetClientIP();
            $this->logger->log($this->logger::INFO, "Session valid.", get_class($this));
            return true;
        }
    }

    public function userIsSessionSet()
    {
        if (isset($_COOKIE['session_id']) && isset($_COOKIE['session_login'])) {
            return $this->userSessionValidate($_COOKIE['session_login'], $_COOKIE['session_id']);
        } else {
            return false;
        }
    }

    private function userSessionEndAll(string $login)
    { }

    public function userSessionEnd(): bool
    {
        if (!isset($_COOKIE['session_id']) && !isset($_COOKIE['session_login'])) {
            $this->logger->log($this->logger::INFO, "No active sessions found!", get_class($this));
            return true;
        }

        $session_id = htmlspecialchars($_COOKIE['session_id']);
        $login = htmlspecialchars($_COOKIE['session_login']);
        $current_date = new \DateTime();
        $session_file_data = $this->userSessionObtainAll($login);

        $session_file_data[$session_id]['date_expires'] = $current_date->format("H:i d-m-Y");

        if ($this->userSessionUpdate($login, $session_file_data) === false) {
            $this->logger->log($this->logger::ERROR, "Failed to end session {$session_id}!", get_class($this));
            return false;
        }

        unset($_COOKIE["session_id"]);
        unset($_COOKIE["session_login"]);
        setcookie("session_id", "", time() - 3600, '/');
        setcookie("session_login", "", time() - 3600, '/');

        $this->logger->log($this->logger::INFO, "Session {$session_id} ended successfully.", get_class($this));

        return true;
    }
}
