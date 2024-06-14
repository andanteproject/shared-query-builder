<?php

declare(strict_types=1);

namespace Andante\Doctrine\ORM\Tests\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Employee extends Person
{
    /** @var Collection<int, Paper> */
    #[ORM\OneToMany(Paper::class, mappedBy: 'employee')]
    private Collection $papers;

    public function __construct()
    {
        $this->papers = new ArrayCollection();
    }

    /**
     * @return Collection<int, Paper>
     */
    public function getPapers(): Collection
    {
        return $this->papers;
    }

    public function addPaper(Paper $paper): self
    {
        $this->papers->add($paper);
        return $this;
    }

    public function removePaper(Paper $paper): self
    {
        $this->papers->removeElement($paper);
        return $this;
    }
}
