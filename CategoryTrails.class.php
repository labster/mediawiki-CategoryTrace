<?php
class CategoryTrails {

    public static function onOutputPageMakeCategoryLinks( &$out, $categories, &$links ) {

        # See OutputPage.php
        # Keep this part from the MW core, so we only process the correct categories
        $titles = array();
        $foundCategories = array();
        $normalcategories = array();
        foreach ( $categories as $category => $type ) {
            // array keys will cast numeric category names to ints, so cast back to string
            $category = (string)$category;
            $origcategory = $category;
            $title = Title::makeTitleSafe( NS_CATEGORY, $category );
            if ( !$title ) {
                continue;
            }
            $wgContLang->findVariantLink( $category, $title, true );
            if ( $category != $origcategory && array_key_exists( $category, $categories ) ) {
                continue;
            }
            // $text = $wgContLang->convertHtml( $title->getText() );
            $out->mCategories[] = $title->getText();
            // $out->mCategoryLinks[$type][] = Linker::link( $title, $text );

            $titles[$category] = $title;
            $foundCategories[$category] = $type;
            if ( $wgCategoryTrailsAllCategories || $type == 'normal' ) {
                $normalCategories[] = $category;
            }

        }

        // Fetch previous and next pages for each category
        $trail = $this->fetchNeighboringPagesMysql( $out->getTitle(), $normalCategories );

        foreach ( $categories as $category => $type ) {
            $text = $wgContLang->convertHtml( $title->getText() );
            $thisLink = Linker::link( $title, $text );

            # Only find pages for type 'normal' to avoid trails for hidden categories (see Skin.php)
            # Unless $wgCategoryTrailsAllCategories config is set.
            if ( $wgCategoryTrailsAllCategories || $type == 'normal' ) {
                $prevLink = $trail['previous'][$cat] ? Linker::linkKnown( $trail['previous'][$cat]) : '';
                $nextLink = $trail['next'    ][$cat] ? Linker::linkKnown( $trail['next'    ][$cat]) : '';

                $thisLink = Html::rawElement( 'span', $prevLink, [ 'class' => 'ct-cattrail' ] )
                          . Html::rawElement( 'span', '←',       [ 'class' => 'ct-catsep' ] )
                          . Html::rawElement( 'span', $thisLink, [ 'class' => 'ct-cattrail' ] )
                          . Html::rawElement( 'span', '→',       [ 'class' => 'ct-catsep' ] )
                          . Html::rawElement( 'span', $nextLink, [ 'class' => 'ct-cattrail' ] );
            }
            $out->mCategoryLinks[$type][] = $thisLink;
        }
        return true;
    }

    # Returns an array of the form
    # $result = [  previous => [ 'cat1' => Title, 'cat2' => Title ],
    #                  next => [ 'cat1' => Title, 'cat2' => Title ] ];
    function fetchNeighboringPages ( Title $page, $categories ) {
        if ($wgDBtype == 'mysql') {
            return $this->fetchNeighboringPagesMysql( $page, $categories );
        }
        else {
            return $this->fetchNeighboringPagesDefaultDB( $page, $categories );
        }
    }
    // Note: There's probably a way to make a performant PostgreSQL version,
    // using window functions, but I don't have a db to test.  But it would be
    // something along the lines of:
    // SELECT prev_title, prev_ns, next_title, next_ns, cl_to FROM (
    //  SELECT cl_to, cl_from
    //         LEAD( page_title ) OVER w AS prev_title, LEAD( page_ns ) OVER w AS prev_ns,
    //         LAG(  page_title ) OVER w AS next_title, LEAD( page_ns ) OVER w AS next_ns,
    //    FROM categorylinks
    //       LEFT JOIN page ON cl_from = page_id
    //   WHERE cl_to IN ( [$categories] )
    //  WINDOW w AS (PARTITION BY cl_to ORDER BY cl_sortkey)
    // ) AS x WHERE cl_from = [$page_id];

    function fetchNeighboringPagesDefaultDB ( Title $page, $categories ) {

        $dbr = wfgetDB( DB_SLAVE );

        # Get sortkey, which is how category table sorts (usually uppercase)
        $page_sortkey = Collation::singleton()->getSortKey(
            $page->getCategorySortkey( $prefix ) );


        foreach ($categories as $cat_name) {

            # Unfortunately there's not really a cheap way to get this information
            # without using a subselect.  The naive approach would be fetching all
            # members of the category instead, and sorting here, but that would be
            # a lot more data sent
            #
            # This won't work correctly when two pages share the same sortkey,
            # *and* are in the same category but that doesn't seem terribly common.
            # (And is probably a symptom of structural problems in your wiki.)

            $subselect_format = '(SELECT cl_from, cl_to, %s AS d FROM categorylinks '.
                'WHERE cl_to = %s AND cl_sortkey %s %s ORDER BY cl_sortkey %s LIMIT 1)';


            $queries[] = sprintf($subselect_format,
                -1, $dbr->addQuotes($cat_name), '<', $dbr->addQuotes($page_sortkey), 'DESC');
            $queries[] = sprintf($subselect_format,
                1,  $dbr->addQuotes($cat_name), '>', $dbr->addQuotes($page_sortkey), 'ASC');

        }

        $subselect = join( ' UNION ALL ', $queries );

        $res = $dbr->query( sprintf( 'SELECT page_title, page_id, page_namespace, cl_to, d '.
            'FROM (%s) AS x LEFT JOIN page ON page_id = x.cl_from',
            $subselect ));

       # Complete query looks like:
       # SELECT page_title, page_id, cl_to, d from (
       #   (SELECT cl_from, cl_to, 1 AS d FROM categorylinks WHERE cl_to = 'Blaxploitation' AND cl_sortkey > 'SHAFT' ORDER BY cl_sortkey ASC limit 1)
       #    union all
       #   (SELECT cl_from, cl_to, -1 AS d FROM categorylinks WHERE cl_to = 'Blaxploitation' AND cl_sortkey < 'SHAFT' ORDER BY cl_sortkey DESC limit 1)
       # ... ) AS x LEFT JOIN page ON page_id = x.cl_from;
       #
       # select page_title, page_id, cl_to, d from ( (select cl_from, cl_to, 1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey > 'SHAFT' order by cl_sortkey asc limit 1) union all (select cl_from, cl_to, -1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey < 'SHAFT' order by cl_sortkey desc limit 1) UNION ALL (select cl_from, cl_to, 1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey > 'Self-Demonstrating_Article' order by cl_sortkey asc limit 1) union all (select cl_from, cl_to, -1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey < 'Self-Demonstrating_Article' order by cl_sortkey desc limit 1) ) AS x left join page on page_id = x.cl_from;


        $prevpage = array();
        $nextpage = array();
        foreach ( $res as $row ) {
            $title = Title::newFromRow( $row );
            if ( $row->d > 0 ) {
                $nextpage[ $row->cl_to ] = $title;
            }
            else {
                $prevpage[ $row->cl_to ] = $title;
            }
        }

        return [ 'previous' => $prevpage, 'next' => $nextpage ];

    }

    function fetchNeighboringPagesMysql ( Title $page, $categories ) {

        $dbr = wfgetDB( DB_SLAVE );

        # I'm sorry to anyone who has to security review this, but there are not
        # a lot of ways to do this without killing the DB with tons of queries.
        # This one works on mysql only.
        #
        # OK, so the strategy here is to make a table, and then use variables to
        # fake self right join it, so that each row is joined to its previous row.
        # This way we only go through the table once,
        # and we use the index for the cl_to field to cull the herd.
        # Then we look for rows with the same sortkey as the current page in both fields,
        # because we're interested in both the row before or after.

        // SELECT cl_to, current_title, current_ns, prev_title, prev_ns FROM (SELECT @prev_ns as prev_ns, @prev_title as prev_title, @prev_cat as prev_cat, @prev_id as prev_id, @prev_cat := cl_to AS cl_to, @prev_ns := page_namespace AS current_ns, @prev_title := page_title AS current_title, @prev_id := cl_from AS current_id FROM ( select @prev_ns := null, @prev_title := null, @prev_cat := null, @prev_id := null ) as i, categorylinks as cl LEFT JOIN page ON page_id = cl_from WHERE cl_to in ('Aria', 'Laconic') ORDER BY cl_to, cl_sortkey ) AS j WHERE prev_cat = cl_to AND (current_id = '42226' OR prev_id = 42226);

        $db_categories = $dbr->makeList( ['cl_to' => $categories] );

        # Add conditions for the outer query
        $outer_conditions = $dbr->makeList([
                'current_id' => $page->getArticleID(),
                'prev_id' => $page->getArticleID()
            ], $dbr::LIST_OR
        );

        $res = $dbr->query(
            "SELECT cl_to, current_title, current_ns, prev_title, prev_ns
                FROM
                (SELECT @prev_ns as prev_ns, @prev_title as prev_title, @prev_cat as prev_cat, @prev_id as prev_id,
                     @prev_cat := cl_to AS cl_to,
                     @prev_ns := page_namespace AS current_ns,
                     @prev_title := page_title AS current_title,
                     @prev_id := cl_from AS current_id
                    FROM ( select @prev_ns := null, @prev_title := null, @prev_cat := null, @prev_id := null ) as i,
                        categorylinks as cl LEFT JOIN page ON page_id = cl_from
                    WHERE $db_categories
                    ORDER BY cl_to, cl_sortkey
                ) AS j
             WHERE prev_cat = cl_to AND ($outer_conditions);"
        );

        $prevpage = array();
        $nextpage = array();
        foreach ( $res as $row ) {
            $prev_title = Title::makeTitle( $row->prev_ns, $row->prev_title );
            if ( $page->equals( $prev_title ) ) {
                $nextpage[ $row->cl_to ] = Title::makeTitle( $row->current_ns, $row->current_title );
            }
            else {
                $prevpage[ $row->cl_to ] = $prev_title;
            }
        }

        return [ 'previous' => $prevpage, 'next' => $nextpage ];

    }


}
