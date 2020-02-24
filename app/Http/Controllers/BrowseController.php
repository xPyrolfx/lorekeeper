<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use DB;
use Auth;
use Settings;
use App\Models\User\User;
use App\Models\Rank\Rank;

use App\Models\Character\Character;
use App\Models\Character\CharacterImage;
use App\Models\Character\CharacterCategory;
use App\Models\Species;
use App\Models\Rarity;
use App\Models\Feature\Feature;

class BrowseController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Browse Controller
    |--------------------------------------------------------------------------
    |
    | Displays lists of users and characters.
    |
    */

    /**
     * Shows the user list.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function getUsers(Request $request)
    {
        $query = User::visible()->join('ranks','users.rank_id', '=', 'ranks.id')->select('ranks.name AS rank_name', 'users.*');
        
        if($request->get('name')) $query->where(function($query) use ($request) {
            $query->where('users.name', 'LIKE', '%' . $request->get('name') . '%')->orWhere('users.alias', 'LIKE', '%' . $request->get('name') . '%');
        });
        if($request->get('rank_id')) $query->where('rank_id', $request->get('rank_id'));

        return view('browse.users', [  
            'users' => $query->orderBy('ranks.sort', 'DESC')->orderBy('name')->paginate(30)->appends($request->query()),
            'ranks' => [0 => 'Any Rank'] + Rank::orderBy('ranks.sort', 'DESC')->pluck('name', 'id')->toArray(),
            'blacklistLink' => Settings::get('blacklist_link')
        ]);
    }

    /**
     * Shows the user blacklist.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function getBlacklist(Request $request)
    {
        $canView = false;
        $key = Settings::get('blacklist_key');

        // First, check the display settings for the blacklist...
        $privacy = Settings::get('blacklist_privacy');
        if ( $privacy == 3 ||
            (Auth::check() &&
            ($privacy == 2 ||
            ($privacy == 1 && Auth::user()->isStaff) ||
            ($privacy == 0 && Auth::user()->isAdmin))))
        {
            // Next, check if the blacklist requires a key
            $canView = true;
            if($key != '0' && ($request->get('key') != $key)) $canView = false;

        }
        return view('browse.blacklist', [ 
            'canView' => $canView, 
            'privacy' => $privacy,
            'key' => $key,
            'users' => $canView ? User::where('is_banned', 1)->orderBy('users.name')->paginate(30)->appends($request->query()) : null,
        ]);
    }

    /**
     * Shows the character masterlist.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function getCharacters(Request $request)
    {
        $query = Character::with('image.features')->myo(0);

        $imageQuery = CharacterImage::query();
        if(!Auth::check() || !Auth::user()->hasPower('manage_characters')) {
            $query->visible();
            $imageQuery->guest();
        }
        
        if($request->get('name')) $query->where(function($query) use ($request) {
            $query->where('characters.name', 'LIKE', '%' . $request->get('name') . '%')->orWhere('characters.slug', 'LIKE', '%' . $request->get('name') . '%');
        });
        if($request->get('rarity_id')) $query->where('rarity_id', $request->get('rarity_id'));
        if($request->get('character_category_id')) $query->where('character_category_id', $request->get('character_category_id'));
        
        if($request->get('sale_value_min')) $query->where('sale_value', '>=', $request->get('sale_value_min'));
        if($request->get('sale_value_max')) $query->where('sale_value', '<=', $request->get('sale_value_max'));

        if($request->get('is_trading')) $query->where('is_trading', 1);
        if($request->get('is_gift_art_allowed')) $query->where('is_gift_art_allowed', 1);
        if($request->get('is_sellable')) $query->where('is_sellable', 1);
        if($request->get('is_tradeable')) $query->where('is_tradeable', 1);
        if($request->get('is_giftable')) $query->where('is_giftable', 1);

        if($request->get('username')) {
            $name = $request->get('username');
            $owners = User::where('name', 'LIKE', '%' . $name . '%')->orWhere('alias', 'LIKE', '%' . $name . '%')->pluck('id')->toArray();
            $query->where(function($query) use ($owners, $name) {
                $query->whereIn('user_id', $owners)->orWhere('owner_alias', 'LIKE', '%' . $name . '%');
            });
        }

        // Search only main images
        if(!$request->get('search_images')) {
            $imageQuery->whereIn('id', $query->pluck('character_image_id')->toArray());
        }

        // Searching on image properties
        if($request->get('species_id')) $imageQuery->where('species_id', $request->get('species_id'));
        if($request->get('feature_id')) {
            $featureIds = $request->get('feature_id');
            foreach($featureIds as $featureId) {
                $imageQuery->whereHas('features', function($query) use ($featureId) {
                    $query->where('feature_id', $featureId);
                });
            }
        }
        if($request->get('artists')) {
            $artistName = $request->get('artists');
            $imageQuery->whereHas('artists', function($query) use ($artistName) {
                $query->where('alias', $artistName);
            });
        }
        if($request->get('designers')) {
            $designerName = $request->get('designers');
            $imageQuery->whereHas('designers', function($query) use ($designerName) {
                $query->where('alias', $designerName);
            });
        }

        $query->whereIn('id', $imageQuery->pluck('character_id')->toArray());

        switch($request->get('sort')) {
            case 'id_desc':
                $query->orderBy('characters.id', 'DESC');
                break;
            case 'id_asc':
                $query->orderBy('characters.id', 'ASC');
                break;
            case 'sale_value_desc':
                $query->orderBy('characters.sale_value', 'DESC');
                break;
            case 'sale_value_asc':
                $query->orderBy('characters.sale_value', 'ASC');
                break;
        }

        return view('browse.masterlist', [  
            'isMyo' => false,
            'characters' => $query->paginate(24)->appends($request->query()),
            'categories' => [0 => 'Any Category'] + CharacterCategory::orderBy('character_categories.sort', 'DESC')->pluck('name', 'id')->toArray(),
            'specieses' => [0 => 'Any Species'] + Species::orderBy('specieses.sort', 'DESC')->pluck('name', 'id')->toArray(),
            'rarities' => [0 => 'Any Rarity'] + Rarity::orderBy('rarities.sort', 'DESC')->pluck('name', 'id')->toArray(),
            'features' => Feature::orderBy('features.name')->pluck('name', 'id')->toArray()
        ]);
    }

    /**
     * Shows the MYO slot masterlist.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function getMyos(Request $request)
    {
        $query = Character::myo(1);

        $imageQuery = CharacterImage::query();
        if(!Auth::check() || !Auth::user()->hasPower('manage_characters')) {
            $query->visible();
            $imageQuery->guest();
        }
        
        if($request->get('name')) $query->where(function($query) use ($request) {
            $query->where('characters.name', 'LIKE', '%' . $request->get('name') . '%')->orWhere('characters.slug', 'LIKE', '%' . $request->get('name') . '%');
        });
        if($request->get('rarity_id')) $query->where('rarity_id', $request->get('rarity_id'));
        
        if($request->get('sale_value_min')) $query->where('sale_value', '>=', $request->get('sale_value_min'));
        if($request->get('sale_value_max')) $query->where('sale_value', '<=', $request->get('sale_value_max'));

        if($request->get('is_trading')) $query->where('is_trading', 1);
        if($request->get('is_sellable')) $query->where('is_sellable', 1);
        if($request->get('is_tradeable')) $query->where('is_tradeable', 1);
        if($request->get('is_giftable')) $query->where('is_giftable', 1);

        if($request->get('username')) {
            $name = $request->get('username');
            $owners = User::where('name', 'LIKE', '%' . $name . '%')->orWhere('alias', 'LIKE', '%' . $name . '%')->pluck('id')->toArray();
            $query->where(function($query) use ($owners, $name) {
                $query->whereIn('user_id', $owners)->orWhere('owner_alias', 'LIKE', '%' . $name . '%');
            });
        }

        // Search only main images
        if(!$request->get('search_images')) {
            $imageQuery->whereIn('id', $query->pluck('character_image_id')->toArray());
        }

        // Searching on image properties
        if($request->get('species_id')) $imageQuery->where('species_id', $request->get('species_id'));
        if($request->get('artists')) {
            $artistName = $request->get('artists');
            $imageQuery->whereHas('artists', function($query) use ($artistName) {
                $query->where('alias', $artistName);
            });
        }
        if($request->get('designers')) {
            $designerName = $request->get('designers');
            $imageQuery->whereHas('designers', function($query) use ($designerName) {
                $query->where('alias', $designerName);
            });
        }
        if($request->get('feature_id')) {
            $featureIds = $request->get('feature_id');
            foreach($featureIds as $featureId) {
                $imageQuery->whereHas('features', function($query) use ($featureId) {
                    $query->where('feature_id', $featureId);
                });
            }
        }

        $query->whereIn('id', $imageQuery->pluck('character_id')->toArray());

        switch($request->get('sort')) {
            case 'id_desc':
                $query->orderBy('characters.id', 'DESC');
                break;
            case 'id_asc':
                $query->orderBy('characters.id', 'ASC');
                break;
            case 'sale_value_desc':
                $query->orderBy('characters.sale_value', 'DESC');
                break;
            case 'sale_value_asc':
                $query->orderBy('characters.sale_value', 'ASC');
                break;
        }

        return view('browse.myo_masterlist', [  
            'isMyo' => true,
            'slots' => $query->paginate(30)->appends($request->query()),
            'specieses' => [0 => 'Any Species'] + Species::orderBy('specieses.sort', 'DESC')->pluck('name', 'id')->toArray(),
            'rarities' => [0 => 'Any Rarity'] + Rarity::orderBy('rarities.sort', 'DESC')->pluck('name', 'id')->toArray(),
            'features' => Feature::orderBy('features.name')->pluck('name', 'id')->toArray()
        ]);
    }
}