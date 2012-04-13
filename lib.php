<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * OAuth library
 * @package   localoauth
 * @copyright 2010 Moodle Pty Ltd (http://moodle.com)
 * @author    Piers Harding
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


// ensure that the correct OAuth libraries are pulled in
require_once('Consumer.php');

class local_oauth_registration {

///////////////////////////
/// DB Facade functions  //
///////////////////////////

    /**
     * Return a site for a given id
     * @param integer $id
     * @return object site, false if null
     */
    public function get_site($id) {
        global $DB;
        return $DB->get_record('oauth_site_directory', array('id'=>$id));
    }

    /**
     * Remove a site from the directory (delete the row from DB)
     * @param integer $id - id of the site to remove from the directory
     * @return boolean true
     * @throws dml_exception if error
     */
    public function delete_site($id) {
        global $DB;
        return $DB->delete_records('oauth_site_directory', array('id'=>$id));
    }

    /**
     * Update a site
     * @param object $site
     * @throws dml_exception if error
     */
    public function update_site($site) {
        global $DB;
        $site->timemodified = time();
        $DB->update_record('oauth_site_directory', $site);
    }

    /**
     * Add a site into the site directory
     * @param object $site
     * @return object site
     * @throws dml_exception if error
     */
    public function add_site($site) {
        global $DB;
        $site->id = $DB->insert_record('oauth_site_directory', $site);
        return $site;
    }


    /**
     * Return sites found against some parameters, by default it returns all visible sites
     * @param string $search String that will be compared to site name
     * @param string $language language code to compare (has to be exact)
     * @param boolean $onlyvisible - set to false to return full list
     * @return array of sites
     */
    public function get_sites($search =null) {
        global $DB;

        $sqlparams = array();
        $wheresql = '';

        if (!empty($search)) {
            $wheresql .= " (name ".$DB->sql_ilike()." :namesearch)";
            $sqlparams['namesearch'] = '%'.$search.'%';
        }

        $sites = $DB->get_records_select('oauth_site_directory', $wheresql, $sqlparams);
        return $sites;
    }

    /**
     * Return a site for a given consumer key
     * @param string $key
     * @return object site , false if null
     */
    public function get_site_by_key($key) {
        global $DB;
        return $DB->get_record('oauth_site_directory', array('consumer_key' => $key));
    }

    /**
     * Return a site for a given name
     * @param string $name
     * @return object site , false if null
     */
    public function get_site_by_name($name) {
        global $DB;
        return $DB->get_record('oauth_site_directory', array('name' => $name));
    }


///////////////////////////
/// Library functions   ///
///////////////////////////

    /**
     * TODO: this is temporary till the way to send file by ws is defined
     * Add a backup to a course
     * @param array $file
     * @param integer $courseid
     */
    public function add_backup($file, $courseid) {

    }

    /**
     * TODO: temporary function till file download design done  (course unique ref not used)
     * Check a backup exists
     * @param int $courseid
     */
    public function backup_exits($courseid) {
        global $CFG;

    }

}

class local_oauth_exception extends moodle_exception { };

class local_oauth {

    protected $site;
    protected $request_token;
    protected $access_token;
    protected $consumer;
    protected $preserve;

    public function __construct($name) {
        global $CFG, $USER, $SESSION, $DB;

        // store the site in the user SESSION
        if (!isset($SESSION->local_oauth)) {
            $SESSION->local_oauth = array();
        }

        // search for things in the SESSION first
        if (isset($SESSION->local_oauth[$name])) {
            if (isset($SESSION->local_oauth[$name])) {
                $this->site = unserialize($SESSION->local_oauth[$name]['site']);
                $this->request_token = unserialize($SESSION->local_oauth[$name]['request_token']);
                $this->access_token = unserialize($SESSION->local_oauth[$name]['access_token']);
                $this->consumer = unserialize($SESSION->local_oauth[$name]['consumer']);
                $this->preserve = unserialize($SESSION->local_oauth[$name]['preserve']);
                return;
            }
        }

        if (!$site = local_oauth_registration::get_site_by_name($name)) {
            throw new local_oauth_exception('notconfigured', 'local_oauth', null, $name);
        }
        $this->site = $site;
        $this->consumer = new local_oauth_Consumer($this->site->consumer_key, $this->site->consumer_secret);
        if ($token = $DB->get_record('oauth_access_token', array('userid' => $USER->id, 'siteid' => $this->site->id))) {
            $this->access_token = unserialize($token->access_token);
        }
        $this->store();
        return;
    }

    /**
    * Is the user authorised
    * @return boolean
    */
    public function is_authorised() {
        if ($this->access_token) {
            return true;
        }
        return false;
    }

    /**
     * Wipe out the stored session data for this oauth session
     * @param array $name name of this site
     * @return nothing
     */
    public function wipe_auth($name) {
        global $CFG, $USER, $DB, $SESSION;

        // search for things in the SESSION first
        $DB->delete_records('oauth_access_token', array('siteid' => $this->site->id, 'userid' => $USER->id));
        $this->request_token = NULL;
        $this->access_token = NULL;
        if (isset($SESSION->local_oauth)) {
            if (isset($SESSION->local_oauth[$name])) {
                unset($SESSION->local_oauth[$name]);
            }
        }
    }


    /**
     * serialise and store the details of the local_oauth object in the SESSION
     * @param array $oauth_params parameters to pass with token request
     * @return bool success/fail
     */
    public function store() {
        global $SESSION, $DB, $USER;
        $name = $this->site->name;
        $SESSION->local_oauth[$name]['site'] = serialize($this->site);
        $SESSION->local_oauth[$name]['request_token'] = serialize($this->request_token);
        $SESSION->local_oauth[$name]['access_token'] = serialize($this->access_token);
        $SESSION->local_oauth[$name]['consumer'] = serialize($this->consumer);
        $SESSION->local_oauth[$name]['preserve'] = serialize($this->preserve);
        if ($old_token = $DB->get_record('oauth_access_token', array('userid' => $USER->id, 'siteid' => $this->site->id))) {
            $old_token->access_token = $SESSION->local_oauth[$name]['access_token'];
            $DB->update_record('oauth_access_token', $old_token);
        }
        else {
            $token = new object();
            $token->userid = $USER->id;
            $token->siteid = $this->site->id;
            $DB->insert_record('oauth_access_token', $token);
        }
    }

    /**
     * get the return to URL with the encoded query string parameters
     * @return string URL
     */
    public function getReturn() {
        global $SESSION;
        $wantsurl = $SESSION->wantsurl;
        unset($SESSION->wantsurl);
        if (empty($wantsurl) ) {
            return null;
        }
        if ($this->preserve) {
            $query = array();
            foreach ($this->preserve as $key => $val) {
                $query[]= $key .'='.urlencode($val);
            }
            if (!empty($query)) {
                $wantsurl = $wantsurl . '?' . implode('&',$query);
            }
            $this->preserve = null;
            $this->store();
        }
        return $wantsurl;
    }


    /**
     * Add a site into the site directory
     * @param array $oauth_params parameters to pass with token request
     * @return bool success/fail
     */
    public function add_to_log($msg, $url='') {
        global $COURSE, $CFG;

        if ($COURSE) {
            add_to_log($COURSE->id, 'OAuth', 'Authentication', $url, $msg);
        }
        else {
            add_to_log(SITEID, 'OAuth', 'Authentication', $url, $msg);
        }
    }


    /**
     * Add a site into the site directory
     * @param array $oauth_params parameters to pass with token request
     * @return bool success/fail
     */
    public function authenticate($oauth_params = array(), $preserve = null) {
        global $CFG, $USER, $SESSION, $FULLME, $COURSE;

        if ($preserve) {
            $this->preserve = $preserve;
            $this->store();
        }

        if (empty($this->access_token)) {
            if (empty($this->request_token)) {
                // obtain a request token
                $this->add_to_log('get request token');
                $this->request_token = $this->consumer->getRequestToken($this->site->request_token_url, $oauth_params);
                $this->store();

                // Authorize the request token
                $SESSION->wantsurl = $FULLME;
                $return_to = new moodle_url("/local/oauth/authenticate.php", array('id' => $this->site->id));
                $this->add_to_log('authorise request token');
                $this->consumer->getAuthorizeRequest($this->site->authorize_token_url, $this->request_token, TRUE, $return_to);
            }

            // Replace the request token with an access token
            $this->add_to_log('upgrade request token to access token');
            $this->access_token = $this->consumer->getAccessToken($this->site->access_token_url, $this->request_token);
            $this->store();
        }

        return true;
    }


	public function getRequest($url, $parameters = array()) {

        return $this->consumer->getRequest($url, $this->access_token, $parameters);
	}

	public function getCSV($url, $parameters = array()) {

	    $response = $this->getRequest($url, $parameters);
        if ($response->status != 200) {
            throw new local_oauth_exception($response->message." : ".$response->body);
        }

        if (empty($response->body)) {
            return null;
        }
        $lines = array();
        foreach (explode("\n", $response->body) as $row) {
            if ($row) {
                $lines[]=  str_getcsv($row, ',', '');
            }
        }
		return $lines;
	}

	public function getCSVTable($url, $parameters = array()) {

	    $data = $this->getCSV($url, $parameters);
        if (empty($data)) {
            return null;
        }
        $header = array_shift($data);
        $table = array();
        foreach ($data as $row) {
            $table[]= array_combine($header, $row);
        }
		return $table;
	}


	public function postRequest($url, $parameters = array()) {

        return $this->consumer->postRequest($url, $this->access_token, $parameters);
	}

}
