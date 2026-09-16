import csv

rows = [
["Sábado de Pasión","Entrega de Guadalcacín","Entrega de Guadalcacín","BCT Santísimo Cristo de la Caridad – Santa Marta (Jerez)","","ambas coinciden",""],
["Sábado de Pasión","Cautivo del Portal","Cautivo del Portal","AM Santa Ángela de la Cruz (Las Cabezas de San Juan)","","solo musicofrades","mundocofrade no cubre esta hermandad"],
["Sábado de Pasión","Humildad de Barbadillo","Humildad de Barbadillo","AM Nuestra Señora de Valme (Dos Hermanas)","","solo musicofrades","mundocofrade no cubre esta hermandad"],
["Sábado de Pasión","Prendimiento de Torrecera","Prendimiento de Torrecera","AM Sagrada Resurrección (Sanlúcar)","","solo musicofrades","mundocofrade no cubre esta hermandad; distinta de Prendimiento/El Prendimiento del Miércoles Santo"],
["Domingo de Ramos","La Borriquita","La Borriquita","BCT Cristo de la Vera-Cruz (Los Palacios)","BM Maestro Enrique Galán (Rota)","ambas coinciden",""],
["Domingo de Ramos","La Coronación","La Coronación","AM La Sentencia (Jerez)","BM Maestro Dueñas (El Puerto)","ambas coinciden",""],
["Domingo de Ramos","El Transporte","El Transporte","BCT Presentación al Pueblo (Sevilla) y BCT Centuria Romana Macarena (Sevilla)","BM Santa Ana (Dos Hermanas)","CONFLICTO -> mundocofrade","musicofrades solo recogia BCT Centuria Romana Macarena, le faltaba BCT Presentacion al Pueblo"],
["Domingo de Ramos","Pasión","Pasión","BCT Amor y Sacrificio (Lebrija)","","ambas coinciden",""],
["Domingo de Ramos","El Perdón","El Perdón","BCT Nuestro Padre Jesús de los Remedios (Castilleja de la Cuesta)","BM Virgen del Castillo (Lebrija)","ambas coinciden",""],
["Domingo de Ramos","Las Angustias","Las Angustias","","Escolanía del Colegio Oratorio Festivo (Jerez)","ambas coinciden (MC da nombre completo)",""],
["Lunes Santo","La Sed","Cristo de la Sed","BCT Coronación de Campillos (Málaga)","","ambas coinciden","nombre popular MC La Sed = titular MF Cristo de la Sed"],
["Lunes Santo","La Paz de Fátima","La Paz de Fátima","AM La Sentencia (Jerez)","Asociación Musical Ecijana (Écija)","ambas coinciden",""],
["Lunes Santo","La Candelaria","La Candelaria","AM Lágrimas de Dolores (San Fernando)","BM Virgen del Castillo (Lebrija)","ambas coinciden",""],
["Lunes Santo","Amor y Sacrificio","Amor y Sacrificio","","No lleva","ambas coinciden","MC No lleva = MF Silencio"],
["Lunes Santo","Sagrada Cena","La Cena","AM Nuestra Señora de la Estrella (Dos Hermanas)","BM Nuestro Padre Jesús Nazareno (Rota)","ambas coinciden","nombre popular MC Sagrada Cena = MF La Cena"],
["Lunes Santo","Cristo de la Viga","La Viga","Capilla Musical Kyrie Eleison (Jerez)","BM Agripino Lozano (San Fernando)","ambas coinciden","MC Cristo de la Viga = MF La Viga"],
["Martes Santo","Bondad y Misericordia","Bondad y Misericordia","AM San Juan (Jerez)","","ambas coinciden",""],
["Martes Santo","La Clemencia","La Clemencia","AM Santísimo Cristo de la Clemencia (Jerez)","BM Municipal de Gerena (Sevilla)","ambas coinciden",""],
["Martes Santo","Salud de San Rafael","Salud de San Rafael","AM La Sentencia (Jerez)","","ambas coinciden",""],
["Martes Santo","La Defensión","La Defensión","BCT Nuestro Padre Jesús en la Presentación al Pueblo (Dos Hermanas)","BM Nuestra Señora de la Soledad (Cantillana)","ambas coinciden",""],
["Martes Santo","La Salvación","La Salvación","AM Polillas (Cádiz)","","ambas coinciden",""],
["Martes Santo","El Amor","El Amor","AM Nuestra Señora de Valme (Dos Hermanas)","BCT Nuestro Padre Jesús Nazareno (Arahal)","ambas coinciden","MF etiqueta ambas como cristo por error de clasificacion, pero los nombres de banda coinciden"],
["Martes Santo","Judíos de San Mateo","Los Judíos de San Mateo","AM Nuestro Padre Jesús de la Redención (Sevilla)","BM Maestro Dueñas (El Puerto de Santa María)","ambas coinciden",""],
["Miércoles Santo","Soberano Poder","El Soberano Poder","AM La Sentencia (Jerez)","","ambas coinciden",""],
["Miércoles Santo","El Consuelo","El Consuelo del Pelirón","AM Sagrada Resurrección (Sanlúcar)","BM Nuestra Señora de Consolación (Huelva)","ambas coinciden",""],
["Miércoles Santo","Las Tres Caídas","Las Tres Caídas","Escolanía Miserere Trío Memoria Eterna y Coral Jesús de las Tres Caídas; BCT Ntro. P. Jesús de las Tres Caídas (Arcos)","BM Música de Gerena (Sevilla)","ambas coinciden","Misterio con dos agrupaciones simultaneas, confirmado en ambas fuentes"],
["Miércoles Santo","La Flagelación","La Amargura","BCT Caridad (Jerez)","BM Julián Cerdán (Sanlúcar de Barrameda)","ambas coinciden","MC La Flagelacion (popular) = MF La Amargura (titular)"],
["Miércoles Santo","Prendimiento","El Prendimiento","AM Nuestro Padre Jesús de la Salud Los Gitanos (Sevilla)","Asociación Filarmónica BM Nuestra Señora de Palomares (Trebujena)","ambas coinciden","OJO: es El Prendimiento, NO Prendimiento de Torrecera (hermandad distinta del Sabado de Pasion)"],
["Jueves Santo","Vera-Cruz","La Vera Cruz","BCT Nazareno (Utrera)","BM Nuestro Padre Jesús Nazareno (Rota)","ambas coinciden","Cruz de Guia propia (Capilla Musical) no cubierta por MC"],
["Jueves Santo","La Redención","La Redención","BCT Santísimo Cristo de la Elevación (Campo de Criptana)","","ambas coinciden",""],
["Jueves Santo","Oración en el Huerto","La Oración en el Huerto","BCT Coronación de Campillos (Málaga)","BM Nuestra Señora de la Soledad (La Algaba)","ambas coinciden",""],
["Jueves Santo","La Lanzada","La Lanzada","BCT Esencia (Sevilla)","","ambas coinciden",""],
["Jueves Santo","Humildad y Paciencia","Humildad y Paciencia","Capilla Musical Calvarium (Sevilla)","","ambas coinciden",""],
["Jueves Santo","Mayor Dolor","Mayor Dolor","BCT Caridad (Jerez)","AF Nuestra Señora de Palomares (Trebujena)","ambas coinciden",""],
["Noche de Jesús","El Silencio","El Santo Crucifijo","No lleva","No lleva","ambas coinciden","MC El Silencio = MF El Santo Crucifijo; ambos pasos sin musica"],
["Noche de Jesús","Cinco Llagas","Las Cinco Llagas","No lleva","No lleva","ambas coinciden",""],
["Noche de Jesús","Nazareno","El Nazareno","AM Sagrada Resurrección (Chiclana)","BM Virgen del Castillo (Lebrija)","CONFLICTO -> mundocofrade","musicofrades tenia BCT Nuestro Padre Jesus del Gran Poder (Coria del Rio) para el Cristo; se sustituye"],
["Noche de Jesús","Buena Muerte","La Buena Muerte","No lleva","No lleva","ambas coinciden",""],
["Noche de Jesús","La Yedra","La Yedra","AM La Sentencia (Jerez)","BM Fernando Guerrero (Los Palacios y Villafranca) y Banda de Música Santa Ana (Dos Hermanas)","CONFLICTO -> mundocofrade","musicofrades solo tenia Fernando Guerrero, le faltaba Santa Ana (Dos Hermanas)"],
["Noche de Jesús","Misión","La Misión","BCT Jesús Despojado (San Fernando) y BCT Nuestra Señora de Gracia (Carmona)","","CONFLICTO -> mundocofrade","musicofrades solo tenia Jesus Despojado, le faltaba Nuestra Senora de Gracia (Carmona)"],
["Viernes Santo","El Loreto","Loreto","","Coro de Capilla San Pedro Nolasco (Jerez)","ambas coinciden (MC da nombre completo)",""],
["Viernes Santo","Las Viñas","La Exaltación","AM Virgen de los Reyes (Sevilla)","BM Municipal de Bollullos (Huelva)","ambas coinciden","MC Las Vinas (popular) = MF La Exaltacion (titular)"],
["Viernes Santo","El Cristo","El Cristo","AM San Juan (Jerez)","BM Nuestra Señora de Palomares (Trebujena)","ambas coinciden","paso de San Juan propio sin musica, ya resuelto en acompanamiento_duda"],
["Viernes Santo","La Soledad","La Soledad","BCT Caridad (Jerez)","BM Maestro Dueñas (El Puerto de Santa María)","ambas coinciden",""],
["Sábado Santo","Sacramental de Santiago","Cristo de las Almas","BCT Fundación Zoilo Ruiz Mateos (Rota)","","ambas coinciden","MC Sacramental de Santiago (popular) = MF Cristo de las Almas (titular)"],
["Sábado Santo","La Mortaja","Sagrada Mortaja","SF de Albaida de Aljarafe (Sevilla)","","CONFLICTO -> mundocofrade","musicofrades no recogia la Coral de Camara A Sei Voci (Los Palacios y Villafranca), que acompaña tambien"],
["Sábado Santo","Santa Marta","Santa Marta","BCT Caridad (Jerez)","BM Nuestra Señora del Rosario (El Cuervo)","ambas coinciden",""],
["Sábado Santo","La Piedad","La Piedad","BM Agripino Lozano (San Fernando)","BM Nuestro Padre Jesús Nazareno (Rota)","ambas coinciden",""],
["Domingo de Resurrección","Resucitado","Sagrada Resurrección","AM San Juan (Jerez)","","ambas coinciden","MC Resucitado (popular) = MF Sagrada Resurreccion (titular)"],
]

with open("scripts/tmp_acompanamientos/jerez_bandas_reconciliado.csv", "w", newline='', encoding='utf-8') as f:
    w = csv.writer(f)
    w.writerow(["DIA","HERMANDAD_POPULAR_MC","HERMANDAD_MF","BANDA_CRISTO","BANDA_VIRGEN","ESTADO","NOTA"])
    w.writerows(rows)

print(f"{len(rows)} filas escritas")
conflictos = [r for r in rows if "CONFLICTO" in r[5]]
print(f"{len(conflictos)} conflictos reales resueltos a favor de mundocofrade")
solo_mf = [r for r in rows if r[5] == "solo musicofrades"]
print(f"{len(solo_mf)} hermandades solo en musicofrades (mundocofrade no las cubre)")
