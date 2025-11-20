<?php

/**
 *
 * Inactive User & Banned User Rank. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2025, Anişor Neculai, https://crimin.us
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace anix\iubur\event;

/**
 * @ignore
 */

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Inactive User & Banned User Rank Event listener.
 */
class main_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup'                           => 'load_language_on_setup',
			'core.viewtopic_get_post_data'              => 'viewtopic_update_post_data',
			'core.memberlist_modify_viewprofile_sql'    => 'update_viewprofile_sql',
			'core.memberlist_modify_memberrow_sql'      => 'memberlist_update_memberrow_sql',
			'core.viewtopic_cache_user_data'            => 'viewtopic_update_cache_user_data',
			'core.viewtopic_modify_post_row'            => 'viewtopic_update_row',
			'core.memberlist_prepare_profile_data'      => 'memberlist_update_profile_data',
		];
	}

	/* @var \phpbb\language\language */
	protected $language;

	/** @var user */
	protected $user;

	/**
	 * Constructor
	 *
	 * @param \phpbb\language\language	$language	Language object
	 */
	public function __construct(
		\phpbb\language\language $language,
		\phpbb\user $user
	) {
		$this->language = $language;
		$this->user = $user;
	}

	/**
	 * Load common language files during user setup
	 *
	 * @param \phpbb\event\data	$event	Event object
	 */
	public function load_language_on_setup($event)
	{
		$lang_set_ext = $event['lang_set_ext'];
		$lang_set_ext[] = [
			'ext_name' => 'anix/iubur',
			'lang_set' => 'common',
		];
		$event['lang_set_ext'] = $lang_set_ext;
	}

	public function viewtopic_update_post_data($event)
	{
		$sql_ary = $event['sql_ary'];
		$this->add_banlist_join($sql_ary);
		$event['sql_ary'] = $sql_ary;
	}

	public function update_viewprofile_sql($event)
	{
		$sql_ary = $event['sql_array'];
		$this->add_banlist_join($sql_ary);
		$event['sql_array'] = $sql_ary;
	}

	public function memberlist_update_memberrow_sql($event)
	{
		$sql_array = $event['sql_array'];
		$this->add_banlist_join($sql_array);
		$event['sql_array'] = $sql_array;
	}

	public function viewtopic_update_cache_user_data($event)
	{
		$user_cache_data = $event['user_cache_data'];
		$row = $event['row'];

		$user_cache_data['ban_userid']      = $row['ban_userid'] ?? 0;
		$user_cache_data['ban_end']         = $row['ban_end'] ?? null;
		$user_cache_data['user_last_active']  = $row['user_last_active'] ?? 0;

		$event['user_cache_data'] = $user_cache_data;
	}

	public function viewtopic_update_row($event)
	{
		$row = $event['row'];
		$post_row = $event['post_row'];
		$user_cache = $event['user_cache'];
		$poster_id = $event['poster_id'];
		$current_time = time();


		if (isset($user_cache[$poster_id])) {
			$user_data = $user_cache[$poster_id];

			$user_ban_end    = $user_data['ban_end'] ?? null;
			$user_last_active = $user_data['user_last_active'] ?? 0;

			$is_banned = ($user_ban_end !== null && ($user_ban_end == 0 || $user_ban_end > $current_time));
			$ban_end_date = ($user_ban_end > 0) ? $this->user->format_date($user_ban_end) : '';
			$time_difference = $current_time - $user_last_active;
			$months_passed = floor($time_difference / (30 * 86400));
			$is_inactive = ($user_last_active > 0 && $user_last_active < $current_time - 30 * 86400);

			if ($is_banned) {
				$post_row['RANK_TITLE'] = ($user_ban_end == 0)
					? $this->user->lang('POSTER_BANNED_PERM')
					: $this->user->lang('POSTER_BANNED_UNTIL', $ban_end_date);
				$post_row['RANK_IMG'] = '';
				$post_row['RANK_IMG_SRC'] = '';
			}

			if ($is_inactive) {
				$post_row['RANK_TITLE'] = $this->user->lang('POSTER_INACTIVE_FOR', $months_passed);
				$post_row['RANK_IMG'] = '';
				$post_row['RANK_IMG_SRC'] = '';
			}
		}

		$event['row'] = $row;
		$event['post_row'] = $post_row;
	}

	public function memberlist_update_profile_data($event)
	{
		$data = $event['data'];
		$template_data = $event['template_data'];
		$current_time = time();

		$user_ban_end    = $data['ban_end'] ?? null;
		$user_last_active = $data['user_last_active'] ?? 0;

		$is_banned = ($user_ban_end !== null && ($user_ban_end == 0 || $user_ban_end > $current_time));
		$ban_end_date = ($user_ban_end > 0) ? $this->user->format_date($user_ban_end) : '';
		$time_difference = $current_time - $user_last_active;
		$months_passed = floor($time_difference / (30 * 86400));
		$is_inactive = ($user_last_active > 0 && $user_last_active < $current_time - 30 * 86400);

		if ($is_banned) {
			$template_data['RANK_TITLE'] = ($user_ban_end == 0)
				? $this->user->lang('POSTER_BANNED_PERM')
				: $this->user->lang('POSTER_BANNED_UNTIL', $ban_end_date);
			$template_data['RANK_IMG'] = '';
			$template_data['RANK_IMG_SRC'] = '';
		}

		if ($is_inactive) {
			$template_data['RANK_TITLE'] = $this->user->lang('POSTER_INACTIVE_FOR', $months_passed);
			$template_data['RANK_IMG'] = '';
			$template_data['RANK_IMG_SRC'] = '';
		}

		$event['template_data'] = $template_data;
	}

	//Join selection with custom function
	private function add_banlist_join(&$sql_array)
	{
		if (!isset($sql_array['LEFT_JOIN'])) {
			$sql_array['LEFT_JOIN'] = [];
		}

		$sql_array['LEFT_JOIN'][] = [
			'FROM' => [BANLIST_TABLE => 'b'],
			'ON'   => 'b.ban_userid = u.user_id',
		];

		$sql_array['SELECT'] .= ', b.ban_userid, b.ban_id, b.ban_end';
	}
}
