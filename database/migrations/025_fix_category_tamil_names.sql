-- Fix corrupted / Latin-only Tamil category labels (charset damage showed as ???).
UPDATE service_categories SET name_ta = 'ஏசி மெக்கானிக்' WHERE code = 'ac';
UPDATE service_categories SET name_ta = 'பிளம்பர்' WHERE code = 'plumber';
UPDATE service_categories SET name_ta = 'மின்சார வேலை' WHERE code = 'electrician';
UPDATE service_categories SET name_ta = 'RO குடிநீர் சேவை' WHERE code = 'ro';
UPDATE service_categories SET name_ta = 'குளிர்சாதனப் பழுதுபார்ப்பு' WHERE code = 'fridge';
UPDATE service_categories SET name_ta = 'சலவை இயந்திரம்' WHERE code = 'wash';
UPDATE service_categories SET name_ta = 'கார் மெக்கானிக்' WHERE code = 'car';
UPDATE service_categories SET name_ta = 'பைக் மெக்கானிக்' WHERE code = 'bike';
