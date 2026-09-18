<?php

namespace App\Services\Operations;

class FictionalCrewNameGenerator
{
    private const FIRST_NAMES = [
        'Lena','Mara','Sophie','Anna','Leonie','Clara','Nina','Julia','Laura','Mia',
        'Jonas','Leon','Felix','Noah','Lukas','David','Daniel','Simon','Julian','Max',
        'Elena','Amelie','Sarah','Paula','Johanna','Emilia','Emma','Marie','Nora','Lisa',
        'Alexander','Florian','Tobias','Sebastian','Philipp','Niklas','Moritz','Tim','Jan','Oliver',
        'Alina','Miriam','Carla','Sofia','Isabel','Valentina','Chiara','Maja','Luisa','Hannah',
        'Adrian','Marco','Milan','Fabian','Christian','Andreas','Matteo','Samuel','Tom','Benjamin',
    ];

    private const LAST_NAMES = [
        'Meyer','Schneider','Fischer','Weber','Wagner','Becker','Hoffmann','Schäfer','Koch','Bauer',
        'Richter','Klein','Wolf','Schröder','Neumann','Schwarz','Zimmermann','Braun','Krüger','Hofmann',
        'Hartmann','Lange','Werner','Schmitz','Krause','Meier','Lehmann','Schmid','Schulze','Maier',
        'Köhler','Herrmann','König','Walter','Mayer','Huber','Kaiser','Fuchs','Peters','Lang',
        'Scholz','Möller','Weiß','Jung','Hahn','Schubert','Vogel','Friedrich','Keller','Günther',
        'Berger','Winter','Frank','Graf','Sommer','Lorenz','Böhm','Seidel','Brandt','Dietrich',
    ];

    public function generate(): array
    {
        return [
            'first_name' => self::FIRST_NAMES[random_int(0, count(self::FIRST_NAMES) - 1)],
            'last_name' => self::LAST_NAMES[random_int(0, count(self::LAST_NAMES) - 1)],
        ];
    }
}
