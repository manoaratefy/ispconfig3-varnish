<?php

/**
 * ISPConfig Nginx Reverse Proxy Plugin.
 *
 * This class extends ISPConfig's vhost management with the functionality to run
 * Nginx in front of Apache2 as a transparent reverse proxy.
 *
 * @author Rackster Internet Services <open-source@rackster.ch>
 * @link   https://open-source.rackster.ch/project/ispconfig3-nginx-reverse-proxy-plugin
 */
class varnish_plugin {

	/**
	 * Stores the internal plugin name.
	 *
	 * @var string
	 */
	var $plugin_name = 'varnish_plugin';

	/**
	 * Stores the internal class name.
	 *
	 * Needs to be the same as $plugin_name.
	 *
	 * @var string
	 */
	var $class_name = 'varnish_plugin';

	/**
	 * Stores the current vhost action.
	 *
	 * When ISPConfig triggers the vhost event, it passes either create,update,delete etc.
	 *
	 * @see onLoad()
	 *
	 * @var string
	 */
	var $action = '';


	/**
	 * ISPConfig onInstall hook.
	 *
	 * Called during ISPConfig installation to determine if a symlink shall be created.
	 *
	 * @return bool create symlink if true
	 */
	function onInstall() {
		global $conf;
		return $conf['services']['web'] == true;
	}

	/**
	 * ISPConfig onLoad hook.
	 *
	 * Register the plugin for some site related events.
	 */
	function onLoad() {
		global $app;

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'ssl');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'ssl');

		$app->plugins->registerEvent('web_domain_insert', $this->plugin_name, 'insert');
		$app->plugins->registerEvent('web_domain_update', $this->plugin_name, 'update');
		$app->plugins->registerEvent('web_domain_delete', $this->plugin_name, 'delete');

		$app->plugins->registerEvent('client_delete', $this->plugin_name, 'client_delete');
	}


	/**
	 * ISPConfig ssl hook.
	 *
	 * Called every time something in the ssl tab is done.
	 *
	 * @see onLoad()
	 * @uses cert_helper()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function ssl($event_name, $data) {
		global $app, $conf;

		$app->uses('system');

		//* Only vhosts can have a ssl cert
		if($data["new"]["type"] != "vhost" && $data["new"]["type"] != "vhostsubdomain") {
			return;
		}

		if ($data['new']['ssl_action'] == 'del') {
			$this->cert_helper('delete', $data);
		} else {
			$this->cert_helper('update', $data);
		}
	}

	/**
	 * ISPConfig insert hook.
	 *
	 * Called every time a new site is created.
	 *
	 * @uses update()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function insert($event_name, $data)	{
		global $app, $conf;

		$this->action = 'insert';
		$this->update($event_name, $data);
	}

	/**
	 * ISPConfig update hook.
	 *
	 * Called every time a site gets updated from within ISPConfig.
	 *
	 * @see insert()
	 * @see delete()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function update($event_name, $data)	{
		global $app, $conf;

		//* $VAR: command to run after vhost insert/update/delete
		$final_command = 'systemctl reload nginx';

		if ($this->action != 'insert') $this->action = 'update';

		$app->uses('getconf');
		$app->uses('system');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

        if($data['new']['ssl'] == 'y'){
            // SSL is enabled. Create/update SSL Termination

    		$app->load('tpl');

    		$tpl = new tpl();
    		$tpl->newTemplate('varnish_plugin_nginx.vhost.conf.master');

            $tpl->setVar('domain', $data['new']['domain']);

            // IPv6 Enabled?
	    	if ($data['new']['ipv6_address'] != '')
			    $tpl->setVar('ipv6_enabled', 1);

            // Get aliases
            $aliases = array();
            
            if($data['new']['subdomain'] != 'none') $aliases[] = $data['new']['subdomain'].'.'.$data['new']['domain'];

            $alias_result = array();
		    $alias_result = $app->dbmaster->queryAllRecords('SELECT * FROM web_domain WHERE parent_domain_id = '.$data['new']['domain_id']." AND active = 'y' AND type != 'vhostsubdomain'");

			foreach($alias_result as $alias) {
                $aliases[] = $alias['domain'];
                if($alias_result['subdomain'] != 'none') $aliases[] = $alias['subdomain'].'.'.$alias['domain'];
			}
            unset($alias_result);
            $aliases = array_unique($aliases);
            $aliases = implode(' ', $aliases);
            $tpl->setVar('aliases', $aliases);

    		$web_folder = 'web';
	    	if($data['new']['type'] == 'vhostsubdomain') {
	    		$tmp = $app->db->queryOneRecord('SELECT `domain` FROM web_domain WHERE domain_id = '.intval($data['new']['parent_domain_id']));
	    		$subdomain_host = preg_replace('/^(.*)\.' . preg_quote($tmp['domain'], '/') . '$/', '$1', $data['new']['domain']);

			    if($subdomain_host == '')
				    $subdomain_host = 'web'.$data['new']['domain_id'];

			    $web_folder = $data['new']['web_folder'];
			    unset($tmp);
		    }

		    $web_document_root_www = $web_config['website_basedir'].'/'.$data['new']['domain'].'/'.$web_folder;
            $tpl->setVar('web_document_root_www', $web_document_root_www);

			$errordocs = !$data['new']['errordocs'];
            $tpl->setVar('errordocs', $errordocs);

			// Check if a SSL cert exists
			if($data['new']['ssl_domain'] == ''){
				// Asume that ssl_domain = domain
				$data['new']['ssl_domain'] = $data['new']['domain'];
			}

            if($data['new']['ssl_letsencrypt'] == 'y'){
                // It is a Let's Encrypt SSL certificate
                $key_file = $data['new']['document_root'].'/ssl/'.$data['new']['ssl_domain'].'-le.key';
                $crt_file = $data['new']['document_root'].'/ssl/'.$data['new']['ssl_domain'].'-le.crt';
            } else{
                // It is an ordinary SSL certificate
                $key_file = $data['new']['document_root'].'/ssl/'.$data['new']['ssl_domain'].'.key';
                $crt_file = $data['new']['document_root'].'/ssl/'.$data['new']['ssl_domain'].'.crt';
            }

            $tpl->setVar('ssl_crt_file', $crt_file);
            $tpl->setVar('ssl_key_file', $key_file);

			if ($this->action == 'insert')
				$this->vhost_helper('insert', $data, $tpl->grab());

			if ($this->action == 'update')
				$vhost_backup = $this->vhost_helper('update', $data, $tpl->grab());
		}

		exec($final_command);

		if (isset($vhost_backup)) {
			$app->system->unlink($vhost_backup['file_new'].'~');
		}

		unset($vhost_backup);
		$this->action = '';
	}

	/**
	 * ISPConfig delete hook.
	 *
	 * Called every time, a site get's removed.
	 *
	 * @uses update()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function delete($event_name, $data) {
		global $app, $conf;

		$this->action = 'delete';

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		if ($data['old']['type'] == 'vhost' || $data['old']['type'] == 'vhostsubdomain') {
			$this->vhost_helper('delete', $data);
		}

		if ($data['old']['type'] == 'alias') {
			$data['new']['type'] == 'alias';
			$this->update($event_name, $data);
		}

		if ($data['old']['type'] == 'subdomain') {
			$data['new']['type'] == 'subdomain';
			$this->update($event_name, $data);
		}
	}

	/**
	 * ISPConfig client delete hook.
	 *
	 * Called every time, a client gets deleted.
	 *
	 * @uses vhost_helper()
	 *
	 * @param string $event_name the event/action name
	 * @param array  $data       the vhost data
	 */
	function client_delete($event_name, $data) {
		global $app, $conf;

		$app->uses('getconf');
		$web_config = $app->getconf->get_server_config($conf['server_id'], 'web');

		$client_id = intval($data['old']['client_id']);
		$client_vhosts = array();
		$client_vhosts = $app->dbmaster->queryAllRecords('SELECT domain FROM web_domain WHERE sys_userid = '. $client_id .' AND parent_domain_id = 0');

		if (count($client_vhosts) > 0) {
			foreach($client_vhosts as $vhost) {
				$data['old']['domain'] = $vhost['domain'];
				$this->vhost_helper('delete', $data);

				$app->log('Removing vhost file: '. $data['old']['domain'], LOGLEVEL_DEBUG);
			}
		}
	}


	/**
	 * ISPConfig internal debug function.
	 *
	 * Function for easier debugging.
	 *
	 * @param string $command executable command to debug
	 */
	private function _exec($command) {
		global $app;

		$app->log('exec: '. $command, LOGLEVEL_DEBUG);
		exec($command);
	}


	/**
	 * Helps managing vhost config files.
	 *
	 * This functions helps to create/delete and link/unlink vhost configs on disk.
	 *
	 * @param string $action the event/action name
	 * @param array  $data   the vhost data
	 * @param mixed  $tpl    vhost template to proceed
     *
	 * @return $data['vhost'] the vhost data
	 */
	private function vhost_helper($action, $data, $tpl = '') {
		global $app;

		$app->uses('system');

		//* $VAR: location of nginx vhost dirs
		$nginx_vhosts = '/etc/nginx/sites-available';
		$nginx_vhosts_enabled = '/etc/nginx/sites-enabled';

		$data['vhost'] = array();

		$data['vhost']['file_old'] = escapeshellcmd($nginx_vhosts .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['link_old'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['old']['domain'] .'.vhost');
		$data['vhost']['file_new'] = escapeshellcmd($nginx_vhosts .'/'. $data['new']['domain'] .'.vhost');
		$data['vhost']['link_new'] = escapeshellcmd($nginx_vhosts_enabled .'/'. $data['new']['domain'] .'.vhost');

		if (is_file($data['vhost']['file_old'])) {
			$data['vhost']['file_old_check'] = 1;
		}

		if (is_file($data['vhost']['file_new'])) {
			$data['vhost']['file_new_check'] = 1;
		}

		if (is_link($data['vhost']['link_old'])) {
			$data['vhost']['link_old_check'] = 1;
		}

		if (is_link($data['vhost']['link_new'])) {
			$data['vhost']['link_new_check'] = 1;
		}

		return $data['vhost'] = call_user_func(
			array(
				$this,
				"vhost_".$action
			),
			$data,
			$app,
			$tpl
		);
	}

	/**
	 * Creates the vhost file and link.
	 *
	 * @param array  $data the vhost data
	 * @param object $app  ISPConfig app object
	 * @param mixed  $tpl  vhost template to proceed
     *
	 * @return $data['vhost'] the vhost data
	 */
	private function vhost_insert($data, $app, $tpl) {
		global $app;

		$app->uses('system');

		$app->system->file_put_contents($data['vhost']['file_new'], $tpl);

		$data['vhost']['file_new_check'] = 1;
		$app->log('Creating vhost file: '. $data['vhost']['file_new'], LOGLEVEL_DEBUG);
		unset($tpl);

		if ($data['vhost']['link_new_check'] != 1) {
			exec('ln -s '. $data['vhost']['file_new'] .' '. $data['vhost']['link_new']);
			$data['vhost']['link_new_check'] = 1;
			$app->log('Creating vhost symlink: '. $data['vhost']['link_new_check'], LOGLEVEL_DEBUG);
		}

		return $data['vhost'];
	}

	/**
	 * Updates the vhost file and link.
	 *
	 * @uses vhost_delete()
	 * @uses vhost_insert()
	 *
	 * @param array  $data the vhost data
	 * @param object $app  ISPConfig app object
	 * @param mixed  $tpl  vhost template to proceed
     *
	 * @return the vhost data
	 */
	private function vhost_update($data, $app, $tpl) {
		global $app;

		$app->uses('system');

		$data['vhost']['link_new_check'] = 0;

		if ($data['new']['active'] == 'n') {
			$data['vhost']['link_new_check'] = 1;
		}

		$data['vhost']['file_new_check'] = 0;

		$this->vhost_delete($data, $app);
		return $this->vhost_insert($data, $app, $tpl);
	}

	/**
	 * Deletes the vhost file and link.
	 *
	 * @param array  $data the vhost data
	 * @param object $app  ISPConfig app object
	 * @param mixed  $tpl  vhost template to proceed
	 */
	private function vhost_delete($data, $app, $tpl = '') {
		global $app;

		$app->uses('system');

		if ($data['vhost']['file_old_check'] == 1) {
			$app->system->unlink($data['vhost']['file_old']);
			$data['vhost']['file_old_check'] = 0;
			$app->log('Removing vhost file: '. $data['vhost']['file_old'], LOGLEVEL_DEBUG);
		}

		if ($data['vhost']['link_old_check'] == 1) {
			$app->system->unlink($data['vhost']['link_old']);
			$data['vhost']['link_old_check'] = 0;
			$app->log('Removing vhost symlink: '. $data['vhost']['link_old'], LOGLEVEL_DEBUG);
		}
	}


	/**
	 * Helps managing SSL cert files.
	 *
	 * This functions helps to create/delete and link/unlink SSL cert files on disk.
	 *
	 * @param string $action the event/action name
	 * @param array  $data   the vhost data
     *
	 * @return $data['cert'] the cert data
	 */
	private function cert_helper($action, $data) {
		global $app;

		$app->uses('system');

		$data['cert'] = array();
		$suffix = 'nginx';
		$ssl_dir = $data['new']['document_root'] .'/ssl';

		$data['cert']['crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.crt');
		$data['cert']['bundle'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.bundle');
		$data['cert'][$suffix .'_crt'] = escapeshellcmd($ssl_dir .'/'. $data['new']['ssl_domain'] .'.'. $suffix .'.crt');

		if (is_file($data['cert']['crt'])) {
			$data['cert']['crt_check'] = 1;
		}

		if (is_file($data['cert'][$suffix .'_crt'])) {
			$data['cert'][$suffix .'_crt_check'] = 1;
		}

		if (is_file($data['cert']['bundle'])) {
			$data['cert']['bundle_check'] = 1;
		}

		return $data['cert'] = call_user_func(
			array(
				$this,
				"cert_".$action
			),
			$data,
			$app,
			$suffix
		);
	}

	/**
	 * Creates the ssl cert files.
	 *
	 * @param array  $data   the vhost data
	 * @param object $app    ISPConfig app object
	 * @param string $suffix cert filename suffix
	 */
	private function cert_insert($data, $app, $suffix) {
		global $app;

		$app->uses('system');

		if ($data['cert']['crt_check'] == 1)	{
			if ($data['cert']['bundle_check'] == 1)	{
                exec('(cat '. $data['cert']['crt'] .'; echo ""; cat '. $data['cert']['bundle'] .') > '. $data['cert'][$suffix .'_crt']);
				$app->log('Merging ssl cert and bundle file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);
			} else {
				$app->system->copy($data['cert']['crt'], $data['cert'][$suffix .'_crt']);
				$app->log('Copying ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);
			}
		} else {
			$app->log('Creating '. $suffix .' ssl files failed', LOGLEVEL_DEBUG);
		}
	}

	/**
	 * Changes the ssl cert files.
	 *
	 * @uses cert_delete()
	 * @uses cert_insert()
	 *
	 * @param array  $data   the vhost data
	 * @param object $app    ISPConfig app object
	 * @param string $suffix cert filename suffix
	 */
	private function cert_update($data, $app, $suffix) {
		global $app;

		$this->cert_delete($data, $app, $suffix);
		$this->cert_insert($data, $app, $suffix);
	}

	/**
	 * Removes the ssl cert files.
	 *
	 * @param array  $data   the vhost data
	 * @param object $app    ISPConfig app object
	 * @param string $suffix cert filename suffix
	 */
	private function cert_delete($data, $app, $suffix) {
		global $app;

		$app->uses('system');

		if ($data['cert'][$suffix .'_crt_check'] == 1) {
			$app->system->unlink($data['cert']['nginx_crt']);
			$app->log('Removing ssl cert file: '. $data['cert'][$suffix .'_crt'], LOGLEVEL_DEBUG);
		}
	}

}
