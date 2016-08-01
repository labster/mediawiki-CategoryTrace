
class CategoryTrails {

    function fetchNeighboringPages ( $categories ) {

        $dbr = wfgetDB( DB_SLAVE );

        # Get sortkey, which is how category table sorts (usually uppercase)
        $sortkey = Collation::singleton()->getSortKey(
			Title->new( $pagename )->getCategorySortkey( $prefix ) );


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



        $subselect = join( ' UNION ALL ', $queries )



        $dbr->sql( sprintf( 'SELECT page_title, page_id, cl_to, d '.
            'FROM (%s) AS x LEFT JOIN page ON page_id = x.cl_from',
            $subselect );


       # Complete query looks like:
       # SELECT page_title, page_id, cl_to, d from (
       #   (SELECT cl_from, cl_to, 1 AS d FROM categorylinks WHERE cl_to = 'Blaxploitation' AND cl_sortkey > 'SHAFT' ORDER BY cl_sortkey ASC limit 1)
       #    union all
       #   (SELECT cl_from, cl_to, -1 AS d FROM categorylinks WHERE cl_to = 'Blaxploitation' AND cl_sortkey < 'SHAFT' ORDER BY cl_sortkey DESC limit 1)
       # ... ) AS x LEFT JOIN page ON page_id = x.cl_from;
       #
       # select page_title, page_id, cl_to, d from ( (select cl_from, cl_to, 1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey > 'SHAFT' order by cl_sortkey asc limit 1) union all (select cl_from, cl_to, -1 AS d from categorylinks where cl_to = 'Blaxploitation' and cl_sortkey < 'SHAFT' order by cl_sortkey desc limit 1) ) AS x left join page on page_id = x.cl_from;

    }

}
