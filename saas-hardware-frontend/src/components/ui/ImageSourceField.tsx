import './ImageSourceField.css';

import { useEffect, useState } from 'react';
import { Link2, Upload, Image as ImageIcon } from 'lucide-react';

interface ImageSourceFieldProps {
  label: string;
  hint?: string;
  /** URL actual (modo "pegar URL"). */
  url: string;
  onUrlChange: (value: string) => void;
  /** Archivo seleccionado (modo "subir"). */
  file: File | null;
  onFileChange: (file: File | null) => void;
  accept?: string;
}

// Campo de imagen que permite elegir entre pegar una URL o subir un archivo,
// con vista previa en vivo de cualquiera de las dos fuentes.
export default function ImageSourceField({
  label,
  hint,
  url,
  onUrlChange,
  file,
  onFileChange,
  accept = 'image/*',
}: ImageSourceFieldProps) {
  const [mode, setMode] = useState<'url' | 'upload'>(file ? 'upload' : 'url');
  const [preview, setPreview] = useState('');

  // Vista previa: objeto local si hay archivo, si no la URL escrita.
  useEffect(() => {
    if (file) {
      const objectUrl = URL.createObjectURL(file);
      setPreview(objectUrl);
      return () => URL.revokeObjectURL(objectUrl);
    }
    setPreview(url);
  }, [file, url]);

  return (
    <div className="img-source-field">
      <div className="img-source-head">
        <label>{label}</label>
        <div className="img-source-tabs">
          <button
            type="button"
            className={mode === 'url' ? 'active' : ''}
            onClick={() => { setMode('url'); onFileChange(null); }}
          >
            <Link2 size={13} /> URL
          </button>
          <button
            type="button"
            className={mode === 'upload' ? 'active' : ''}
            onClick={() => setMode('upload')}
          >
            <Upload size={13} /> Subir
          </button>
        </div>
      </div>

      <div className="img-source-body">
        {preview ? (
          <img className="img-source-preview" src={preview} alt="" />
        ) : (
          <span className="img-source-preview placeholder"><ImageIcon size={16} /></span>
        )}

        {mode === 'url' ? (
          <input
            className="premium-input"
            type="url"
            value={url}
            placeholder="https://..."
            onChange={(e) => { onUrlChange(e.target.value); onFileChange(null); }}
          />
        ) : (
          <input
            className="premium-input file-input"
            type="file"
            accept={accept}
            onChange={(e) => onFileChange(e.target.files?.[0] ?? null)}
          />
        )}
      </div>

      {hint && <span className="img-source-hint">{hint}</span>}

    </div>
  );
}
