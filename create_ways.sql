CREATE TABLE ways (osm_id bigint, sp geometry, dp geometry,  name text, highway text, 
	oneway text, z_order integer, distance double precision, weight double precision DEFAULT 5.0) 
	WITH ( OIDS=FALSE );
ALTER TABLE ways OWNER TO postgres;

CREATE OR REPLACE FUNCTION createWays() RETURNS int8 AS $$
DECLARE
    numb int;
BEGIN
    FOR numb IN 1..2000
    LOOP
	INSERT INTO ways 
		SELECT osm_id, ST_PointN(ST_GeomFromKML(ST_AsKML(way)),numb) as sp, 
			ST_PointN(ST_GeomFromKML(ST_AsKML(way)),numb+1) as dp, name, highway, oneway, z_order
	FROM planet_osm_roads WHERE ST_NumPoints(way) > numb; 
	INSERT INTO ways 
		SELECT osm_id, ST_PointN(ST_GeomFromKML(ST_AsKML(way)),numb) as sp, 
			ST_PointN(ST_GeomFromKML(ST_AsKML(way)),numb+1) as dp, name, highway, oneway, z_order
	FROM planet_osm_line WHERE ST_NumPoints(way) > numb; 
    END LOOP;
    RETURN numb;
END
$$ LANGUAGE 'plpgsql' ;

SELECT * FROM createWays();

UPDATE ways SET weight=1 WHERE highway LIKE 'primary%';
UPDATE ways SET weight=2 WHERE highway LIKE 'secondary%';
UPDATE ways SET weight=10 WHERE highway LIKE 'motorway%';
UPDATE ways SET weight=10 WHERE highway LIKE 'trunk%';

UPDATE ways SET distance=ST_Distance(sp,dp);

UPDATE ways SET oneway='yes' WHERE oneway LIKE 'true%';
