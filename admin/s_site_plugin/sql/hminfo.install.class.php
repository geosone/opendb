<?php
/* 	
 	Open Media Collectors Database
	Copyright (C) 2001,2013 by Jason Pell

	This program is free software; you can redistribute it and/or
	modify it under the terms of the GNU General Public License
	as published by the Free Software Foundation; either version 2
	of the License, or (at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
*/

include_once("./lib/Install_Table.class.php");
include_once("./lib/install.php");

function site_hminfo_install()
{
	$query = "CREATE TABLE site_hminfo (".
	"	id 		INTEGER(20) UNSIGNED NOT NULL,".
	"	dvd_title 	VARCHAR(128),".
	"	studio		VARCHAR(30),".
	"	released	DATE,".
	"	status 		VARCHAR(15),".
	"	sound 		VARCHAR(20),".
	"	versions	VARCHAR(20),".
	"	price		DECIMAL(12,2),".
	"	rating		VARCHAR(5),".
	"	year		VARCHAR(5),".
	"	genre		VARCHAR(20),".
	"	aspect		VARCHAR(6),".
	"	upc		VARCHAR(15),".
	"	dvd_releasedate DATE,".
	"	timestamp	DATE,".
	"	update_on	TIMESTAMP,".
	"	PRIMARY KEY ( id )".
	") ENGINE=MyISAM COMMENT='Home Theatre Info Lookup Table';";
	
	$create = db_query($query);
	if ($create )
	{
		opendb_logger(OPENDB_LOG_INFO, __FILE__, __FUNCTION__, 'Table site_hminfo created');
		return TRUE;
	}
	else
	{
		opendb_logger(OPENDB_LOG_ERROR, __FILE__, __FUNCTION__, db_error());
		return FALSE;
	}
}

/**
* @param row_array - corresponds to each of the columns in the table.
*/
function site_hminfo_insert($id, $dvd_title, $studio, $released, $status, $sound, $versions, $price, $rating, $year, $genre, $aspect, $upc, $dvd_releasedate, $timestamp)
{
	$dvd_title = $dvd_title;
	$studio = $studio;
	$genre = $genre;
	
	$query = "INSERT INTO site_hminfo(id, dvd_title, studio, released, status, sound, versions, price, rating, year, genre, aspect, upc, dvd_releasedate, timestamp) ".
			"VALUES($id, '" . db_escape($dvd_title) . "', '" . db_escape($studio) . "', ".($released!=NULL?"'" . db_escape($released) . "'":"NULL").", '" . db_escape($status) . "', '" . db_escape($sound) . "', '" . db_escape($versions) . "', '" . db_escape($price) . "', '" . db_escape($rating) . "', '" . db_escape($year) . "', '" . db_escape($genre) . "', '" . db_escape($aspect) . "', '" . db_escape($upc) . "', ".($dvd_releasedate!=NULL?"'" . db_escape($dvd_releasedate) . "'":"NULL").", ".($timestamp!=NULL?"'" . db_escape($timestamp) . "'":"NULL").")";
	
	$insert = db_query($query);
	if ($insert && db_affected_rows() > 0)
	{
		return TRUE;
	}
	else
	{
		return FALSE;
	}
}

function site_hminfo_update($id, $dvd_title, $studio, $released, $status, $sound, $versions, $price, $rating, $year, $genre, $aspect, $upc, $dvd_releasedate, $timestamp)
{
	$dvd_title = $dvd_title;
	$studio = $studio;
	$genre = $genre;
	
	$query = "UPDATE site_hminfo ".
			"SET dvd_title = '" . db_escape($dvd_title) . "' ".
			", studio = '" . db_escape($studio) . "' ".
			($released!=NULL?", released = '" . db_escape($released) . "'":"").
			", status =  '" . db_escape($status) . "'". 
			", sound = '" . db_escape($sound) . "'". 
			", versions = '" . db_escape($versions) . "'". 
			", price = '" . db_escape($price) . "'". 
			", rating = '" . db_escape($rating) . "'".
			", year = '" . db_escape($year) . "'".
			", genre = '" . db_escape($genre) . "'".
			", aspect = '" . db_escape($aspect) . "'".
			($dvd_releasedate!=NULL?", dvd_releasedate = '" . db_escape($dvd_releasedate) . "'":"").
			($timestamp!=NULL?", timestamp = '" . db_escape($timestamp) . "'":"").
			", upc = '" . db_escape($upc) . "' ".
			"WHERE id = " . db_int($id);
	
	$update = db_query($query);
	$rows_affected = db_affected_rows();
	if($update && $rows_affected !== -1)
	{
		return TRUE;
	}
	else
	{
		return FALSE;
	}
}

function site_hminfo_delete($id)
{
	$query ="DELETE FROM site_hminfo WHERE id = '".db_escape($id)."'";
	$delete = db_query($query);
	if($delete && db_affected_rows() > 0)
	{
		return TRUE;
	}
	else
	{
		return FALSE;
	}
}

function site_hminfo_exists($id)
{
	$query = "SELECT 'x' FROM site_hminfo WHERE id = " . db_int($id);

	$result = db_query($query);
	if($result && db_num_rows($result)>0)
	{
		db_free_result($result);
		return TRUE;
	}

	//else
	return FALSE;
}

function convert_hminfo_datetime_to_date($date)
{
	if(strlen($date)>0)
	{
		//2002-11-19 00:00:00
		list($year, $month, $day) = sscanf($date,"%d-%d-%d %d:%d:%d");
		//mysql compatible DATE format
		return $year."-".$month."-".$day;
	}
	else
	{
		return NULL;
	}
}

class Install_hminfo extends Install_Table
{
	function Install_hminfo()
	{
		parent::Install_Table('site_hminfo');
	}
	
	function doInstallTable() {
		site_hminfo_install();
	}
	
	/**
	*/
	function handleRow($row_data, &$error)
	{
		//"DVD_Title","Studio","Released","Status","Sound","Versions","Price","Rating",
		//"Year","Genre","Aspect","UPC","DVD_ReleaseDate","ID","Timestamp", "Updated"
		$ID = trim($row_data['id']);
		if(site_hminfo_exists($ID))
		{
			if(site_hminfo_update(
					$ID,
					trim($row_data['dvd_title']),
					trim($row_data['studio']),
					convert_hminfo_datetime_to_date(trim($row_data['released'])),
					trim($row_data['status']),
					trim($row_data['sound']),
					trim($row_data['versions']),
					trim($row_data['price']),
					trim($row_data['rating']),
					trim($row_data['year']),
					trim($row_data['genre']),
					trim($row_data['aspect']),
					trim($row_data['upc']),
					convert_hminfo_datetime_to_date(trim($row_data['dvd_releasedate'])),
					convert_hminfo_datetime_to_date(trim($row_data['timestamp']))))
			{
				return '__UPDATE__';
			}
			else
			{
				$error = db_error();
				return '__UPDATE_FAILED__';
			}
		}
		else
		{
			if(site_hminfo_insert(
					$ID,//ID
					trim($row_data['dvd_title']),
					trim($row_data['studio']),
					convert_hminfo_datetime_to_date(trim($row_data['released'])),
					trim($row_data['status']),
					trim($row_data['sound']),
					trim($row_data['versions']),
					trim($row_data['price']),
					trim($row_data['rating']),
					trim($row_data['year']),
					trim($row_data['genre']),
					trim($row_data['aspect']),
					trim($row_data['upc']),
					convert_hminfo_datetime_to_date(trim($row_data['dvd_releasedate'])),
					convert_hminfo_datetime_to_date(trim($row_data['timestamp']))))
			{
				return '__INSERT__';
			}
			else
			{
				$error = db_error();
				return '__INSERT_FAILED__';
			}
		}
	}
}	
?>
